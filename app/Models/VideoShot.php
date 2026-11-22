<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\VideoShotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One storyboard shot and, once generated, its video clip.
 *
 * @property int $id
 * @property string $ulid
 * @property int $video_project_id
 * @property int $source_asset_id
 * @property int|null $tour_scene_id
 * @property int $sort_order
 * @property int $duration Seconds
 * @property string $camera_movement
 * @property string|null $scene_name
 * @property string $prompt
 * @property string|null $negative_prompt
 * @property string $transition crossfade|fade_black|cut
 * @property string $status pending|generating|completed|failed
 * @property int|null $generation_id
 * @property int|null $output_asset_id
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'source_asset_id', 'tour_scene_id', 'sort_order', 'duration', 'camera_movement', 'scene_name', 'prompt',
    'negative_prompt', 'transition', 'status', 'generation_id', 'output_asset_id', 'metadata',
])]
class VideoShot extends Model
{
    /** @use HasFactory<VideoShotFactory> */
    use HasFactory, HasPublicUlid;

    public const CAMERA_MOVEMENTS = [
        'slow push-in', 'slow pull-out', 'gentle forward dolly', 'lateral slide left', 'lateral slide right',
        'slow pan left', 'slow pan right', 'tilt up reveal', 'orbit', 'rising aerial reveal', 'static with subtle parallax',
    ];

    public const TRANSITIONS = ['crossfade', 'fade_black', 'cut'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'duration' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<VideoProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'source_asset_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function outputAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'output_asset_id');
    }

    /**
     * @return BelongsTo<AiGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'generation_id');
    }
}
