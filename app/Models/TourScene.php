<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\TourSceneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $virtual_tour_id
 * @property int $media_asset_id
 * @property string $title
 * @property string|null $description
 * @property string $scene_type
 * @property int $sort_order
 * @property string $initial_yaw Degrees in [-180, 180]
 * @property string $initial_pitch Degrees in [-90, 90]
 * @property int $initial_zoom Viewer zoom level in [0, 100]
 * @property float|null $latitude Map pin, degrees in [-90, 90]
 * @property float|null $longitude Map pin, degrees in [-180, 180]
 * @property bool $is_visible
 * @property array<string, mixed>|null $audio_settings
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'media_asset_id', 'title', 'description', 'scene_type', 'sort_order', 'initial_yaw',
    'initial_pitch', 'initial_zoom', 'latitude', 'longitude', 'is_visible', 'audio_settings', 'metadata',
])]
class TourScene extends Model
{
    /** @use HasFactory<TourSceneFactory> */
    use HasFactory, HasPublicUlid;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'initial_yaw' => 'decimal:3',
            'initial_pitch' => 'decimal:3',
            'initial_zoom' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'is_visible' => 'boolean',
            'audio_settings' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<VirtualTour, $this>
     */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(VirtualTour::class, 'virtual_tour_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * @return HasMany<TourHotspot, $this>
     */
    public function hotspots(): HasMany
    {
        return $this->hasMany(TourHotspot::class)->orderBy('sort_order')->orderBy('id');
    }
}
