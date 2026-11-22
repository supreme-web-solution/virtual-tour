<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tour_floorplan_id
 * @property int $tour_scene_id
 * @property string $x Fraction of image width (0–1)
 * @property string $y Fraction of image height (0–1)
 */
#[Fillable(['tour_scene_id', 'x', 'y'])]
class TourFloorplanMarker extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['x' => 'decimal:5', 'y' => 'decimal:5'];
    }

    /**
     * @return BelongsTo<TourScene, $this>
     */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(TourScene::class, 'tour_scene_id');
    }
}
