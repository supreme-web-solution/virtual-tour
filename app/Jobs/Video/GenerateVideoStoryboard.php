<?php

namespace App\Jobs\Video;

use App\Contracts\Media\MediaStorage;
use App\Contracts\Video\VideoGenerationProvider;
use App\Enums\AssetType;
use App\Enums\GenerationStatus;
use App\Enums\VideoStatus;
use App\Exceptions\ProviderException;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Services\AI\GenerationLifecycle;
use App\Services\AI\VideoPlanningService;
use App\Services\Video\VideoStudioService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateVideoStoryboard implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 150;

    public function __construct(public AiGeneration $generation)
    {
        $this->onQueue('ai-analysis');
    }

    public function uniqueId(): string
    {
        return (string) $this->generation->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(
        VideoPlanningService $planner,
        VideoStudioService $studio,
        VideoGenerationProvider $videoProvider,
        MediaStorage $storage,
        GenerationLifecycle $lifecycle,
    ): void {
        $generation = $this->generation->refresh();
        $project = $generation->videoProject;

        if ($generation->status->isFinal() || ! $project) {
            return;
        }

        $ids = array_values((array) $generation->input('source_asset_ids', []));
        $positions = array_flip($ids);
        $assets = MediaAsset::where('workspace_id', $project->workspace_id)
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (MediaAsset $asset): int => (int) ($positions[$asset->id] ?? PHP_INT_MAX))
            ->values();

        $sources = array_values($assets->map(fn (MediaAsset $asset): array => [
            'id' => $asset->ulid,
            'label' => $asset->asset_type === AssetType::Panorama
                ? 'forward view of 360° scene "'.pathinfo((string) $asset->original_filename, PATHINFO_FILENAME).'"'
                : (string) pathinfo((string) $asset->original_filename, PATHINFO_FILENAME),
            'image_url' => $asset->asset_type === AssetType::Panorama
                ? $storage->panoramaViewUrl($asset->cloudinary_public_id, '16:9', 1024)
                : $storage->scaledImageUrl($asset->cloudinary_public_id, 1024),
        ])->all());

        if ($sources === []) {
            $lifecycle->fail($generation, 'None of the selected images exist any more.');
            $project->update(['status' => VideoStatus::Failed]);

            return;
        }

        $lifecycle->advance($generation, GenerationStatus::Processing);

        try {
            ['storyboard' => $storyboard, 'result' => $result] = $planner->plan(
                $project->style,
                $project->aspect_ratio,
                $project->requested_duration,
                $sources,
                $videoProvider->capabilities(),
                $project->settings()['brief'],
            );
        } catch (ProviderException $exception) {
            if ($exception->retryable && $this->attempts() < $this->tries) {
                throw $exception;
            }

            $this->failed($exception);

            return;
        }

        $generation->refresh();
        $generation->model = $result->model;
        $generation->actual_cost = $result->costUsd;
        $generation->mergeOutput(['storyboard' => $storyboard]);
        $generation->save();

        $studio->applyStoryboard($project, $storyboard);
        $lifecycle->complete($generation);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationLifecycle::class)->fail($this->generation, $exception?->getMessage() ?? 'Storyboard planning failed.');
        $this->generation->videoProject?->update(['status' => VideoStatus::Failed]);
    }
}
