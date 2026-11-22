<?php

namespace App\Jobs\AI;

use App\Enums\GenerationStatus;
use App\Exceptions\ProviderException;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Services\AI\GenerationLifecycle;
use App\Services\AI\PropertyAnalysisService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalysePropertyPhotos implements ShouldBeUnique, ShouldQueue
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

    public function handle(PropertyAnalysisService $analyser, GenerationLifecycle $lifecycle): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status->isFinal()) {
            return;
        }

        $ids = array_values((array) $generation->input('photo_ids', []));
        $positions = array_flip($ids);
        $photos = array_values(MediaAsset::where('workspace_id', $generation->workspace_id)
            ->whereIn('ulid', $ids)
            ->get()
            ->sortBy(fn (MediaAsset $photo): int => (int) ($positions[$photo->ulid] ?? PHP_INT_MAX))
            ->all());

        if ($photos === []) {
            $lifecycle->fail($generation, 'None of the selected photos exist any more.');

            return;
        }

        $lifecycle->advance($generation, GenerationStatus::Processing);

        try {
            ['analysis' => $analysis, 'result' => $result] = $analyser->analyse($photos);
        } catch (ProviderException $exception) {
            if ($exception->retryable && $this->attempts() < $this->tries) {
                throw $exception;
            }

            $lifecycle->fail($generation, $exception->getMessage());

            return;
        }

        $generation->refresh();
        $generation->model = $result->model;
        $generation->provider_request_id = $result->providerRequestId;
        $generation->actual_cost = $result->costUsd;
        $generation->mergeOutput([
            'analysis' => $analysis,
            'usage' => ['input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens],
        ]);
        $generation->save();

        $lifecycle->complete($generation);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationLifecycle::class)->fail($this->generation, $exception?->getMessage() ?? 'Photo analysis failed.');
    }
}
