<?php

namespace App\Services\Video;

use App\Contracts\Media\MediaStorage;
use App\Enums\AssetType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoShot;
use App\Services\Domains\PublicUrl;

class VideoPresenter
{
    public function __construct(protected MediaStorage $storage) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(VideoProject $project, ?User $viewer = null): array
    {
        return [
            'id' => $project->ulid,
            'public_id' => $project->public_id,
            'title' => $project->title,
            'style' => $project->style->value,
            'style_label' => $project->style->label(),
            'aspect_ratio' => $project->aspect_ratio,
            'requested_duration' => $project->requested_duration,
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
            'poster_url' => $project->posterAsset->secure_url ?? null,
            'property' => $project->property === null ? null : ['id' => $project->property->ulid, 'name' => $project->property->name],
            'is_published' => $project->isPublished(),
            'updated_at' => $project->updated_at?->toIso8601String(),
            'can' => ['delete' => $viewer?->can('delete', $project) ?? false],
        ];
    }

    /**
     * Full studio payload.
     *
     * @return array<string, mixed>
     */
    public function project(VideoProject $project, User $viewer): array
    {
        $project->loadMissing(['shots.sourceAsset', 'shots.outputAsset', 'shots.generation', 'soundtrack', 'voiceover', 'finalAsset', 'posterAsset', 'property', 'tour']);

        $shots = $project->shots;
        $completed = $shots->where('status', 'completed')->count();
        $latestExport = $project->exports()->latest('id')->first();
        $music = $project->generations()->where('generation_type', GenerationType::MusicTrack)->latest('id')->first();
        $storyboardGeneration = $project->generations()->where('generation_type', GenerationType::VideoStoryboard)->latest('id')->first();

        return [
            ...$this->summary($project, $viewer),
            'resolution' => $project->resolution,
            'provider' => $project->provider,
            'model' => $project->model,
            'storyboard' => $project->storyboard,
            'storyboard_error' => $storyboardGeneration?->status === GenerationStatus::Failed ? $storyboardGeneration->error_message : null,
            'settings' => $project->settings(),
            'soundtrack_mode' => $project->soundtrack_mode->value,
            'soundtrack' => $this->audio($project->soundtrack),
            'voiceover' => $this->audio($project->voiceover),
            'music_generation' => $music === null ? null : [
                'status' => $music->status->value,
                'error' => $music->error_message,
            ],
            'tour' => $project->tour === null ? null : ['id' => $project->tour->ulid, 'title' => $project->tour->title],
            'progress' => ['completed' => $completed, 'total' => $shots->count()],
            'total_seconds' => (int) $shots->sum('duration'),
            'shots' => array_values($shots->map(fn (VideoShot $shot): array => $this->shot($shot))->all()),
            'final' => $project->finalAsset === null ? null : [
                'url' => $project->finalAsset->secure_url,
                'poster_url' => $project->posterAsset?->secure_url,
                'duration' => $project->finalAsset->duration !== null ? (float) $project->finalAsset->duration : null,
            ],
            'latest_export' => $latestExport === null ? null : [
                'status' => $latestExport->status,
                'error' => $latestExport->error_message,
                'completed_at' => $latestExport->completed_at?->toIso8601String(),
            ],
            'visibility' => $project->visibility->value,
            'published_at' => $project->published_at?->toIso8601String(),
            'public_url' => app(PublicUrl::class)->video($project),
            'stats' => [
                'views' => $project->views_count,
                'plays' => $project->plays_count,
                'completions' => $project->completions_count,
                'downloads' => $project->downloads_count,
                'embed_views' => $project->embed_views_count,
            ],
            'is_active' => in_array($project->status->value, ['planning', 'generating', 'rendering'], true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function shot(VideoShot $shot): array
    {
        $source = $shot->sourceAsset;

        return [
            'id' => $shot->ulid,
            'sort_order' => $shot->sort_order,
            'duration' => $shot->duration,
            'camera_movement' => $shot->camera_movement,
            'scene_name' => $shot->scene_name,
            'prompt' => $shot->prompt,
            'transition' => $shot->transition,
            'status' => $shot->status,
            'error' => $shot->status === 'failed' ? ($shot->generation->error_message ?? $shot->metadata['error'] ?? null) : null,
            'source' => [
                'id' => $source->ulid,
                'name' => $source->original_filename,
                'is_panorama' => $source->asset_type === AssetType::Panorama,
                'thumbnail_url' => $source->asset_type === AssetType::Panorama
                    ? $this->storage->panoramaViewUrl($source->cloudinary_public_id, '16:9', 480)
                    : $source->thumbnail_url,
            ],
            'clip' => $shot->outputAsset === null ? null : [
                'url' => $shot->outputAsset->secure_url,
                'thumbnail_url' => $shot->outputAsset->thumbnail_url,
            ],
        ];
    }

    /**
     * @return array{id: string, name: string|null, url: string, duration: float|null}|null
     */
    protected function audio(?MediaAsset $asset): ?array
    {
        return $asset === null ? null : [
            'id' => $asset->ulid,
            'name' => $asset->original_filename,
            'url' => $asset->secure_url,
            'duration' => $asset->duration !== null ? (float) $asset->duration : null,
        ];
    }

    /**
     * Credits a "generate clips" action would hold right now.
     */
    public function clipCost(VideoProject $project, bool $includeMusic): int
    {
        $shots = $project->shots()->whereIn('status', ['pending', 'failed'])->get(['duration']);
        $cost = $shots->sum(fn (VideoShot $shot): int => GenerationType::VideoClip->credits(['duration' => $shot->duration]));

        return $cost + ($includeMusic ? GenerationType::MusicTrack->credits() : 0);
    }
}
