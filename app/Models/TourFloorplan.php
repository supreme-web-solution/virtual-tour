<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $ulid
 * @property int $virtual_tour_id
 * @property int $media_asset_id
 * @property string $title
 * @property int $sort_order
 */
#[Fillable(['media_asset_id', 'title', 'sort_order'])]
class TourFloorplan extends Model
{
    use HasPublicUlid;

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
     * @return HasMany<TourFloorplanMarker, $this>
     */
    public function markers(): HasMany
    {
        return $this->hasMany(TourFloorplanMarker::class);
    }
}
