<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tour_visit_id
 * @property int $virtual_tour_id
 * @property string $type scene_view|hotspot_click|link_click|lead_submitted
 * @property int|null $tour_scene_id
 * @property int|null $tour_hotspot_id
 * @property CarbonInterface $created_at
 */
#[Fillable(['tour_visit_id', 'virtual_tour_id', 'type', 'tour_scene_id', 'tour_hotspot_id', 'created_at'])]
class TourEvent extends Model
{
    public const TYPES = ['scene_view', 'hotspot_click', 'link_click', 'lead_submitted'];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
