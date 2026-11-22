<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An anonymous viewing session of a public tour.
 *
 * @property int $id
 * @property string $ulid
 * @property int $virtual_tour_id
 * @property int $workspace_id
 * @property string $visitor_hash
 * @property string $device_type
 * @property string|null $country
 * @property string|null $referrer_host
 * @property bool $is_embed
 * @property int $scene_count
 * @property int $scenes_viewed
 * @property CarbonInterface $started_at
 * @property CarbonInterface $last_seen_at
 */
#[Fillable([
    'virtual_tour_id', 'workspace_id', 'visitor_hash', 'device_type', 'country', 'referrer_host',
    'is_embed', 'scene_count', 'scenes_viewed', 'started_at', 'last_seen_at',
])]
class TourVisit extends Model
{
    use HasPublicUlid;

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
        return [
            'is_embed' => 'boolean',
            'scene_count' => 'integer',
            'scenes_viewed' => 'integer',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<TourEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TourEvent::class);
    }
}
