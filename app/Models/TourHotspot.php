<?php

namespace App\Models;

use App\Enums\HotspotType;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\TourHotspotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tour_scene_id
 * @property int|null $target_scene_id
 * @property int|null $media_asset_id
 * @property HotspotType $type
 * @property string $yaw Degrees in [-180, 180]
 * @property string $pitch Degrees in [-90, 90]
 * @property string|null $title
 * @property string|null $description
 * @property string|null $external_url
 * @property array<string, mixed>|null $settings
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'target_scene_id', 'media_asset_id', 'type', 'yaw', 'pitch', 'title', 'description',
    'external_url', 'settings', 'sort_order',
])]
class TourHotspot extends Model
{
    /** @use HasFactory<TourHotspotFactory> */
    use HasFactory, HasPublicUlid;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => HotspotType::class,
            'yaw' => 'decimal:3',
            'pitch' => 'decimal:3',
            'settings' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TourScene, $this>
     */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(TourScene::class, 'tour_scene_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * @return BelongsTo<TourScene, $this>
     */
    public function targetScene(): BelongsTo
    {
        return $this->belongsTo(TourScene::class, 'target_scene_id');
    }
}
