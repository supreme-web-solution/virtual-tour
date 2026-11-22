<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rendered MP4 of a video project.
 *
 * @property int $id
 * @property string $ulid
 * @property int $video_project_id
 * @property int|null $requested_by
 * @property string $status queued|rendering|completed|failed
 * @property int|null $media_asset_id
 * @property int|null $poster_asset_id
 * @property string|null $duration
 * @property int|null $width
 * @property int|null $height
 * @property array<string, mixed>|null $settings
 * @property string|null $error_message
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 */
#[Fillable([
    'requested_by', 'status', 'media_asset_id', 'poster_asset_id', 'duration', 'width', 'height',
    'settings', 'error_message', 'started_at', 'completed_at',
])]
class VideoExport extends Model
{
    use HasPublicUlid;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'duration' => 'decimal:3',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
