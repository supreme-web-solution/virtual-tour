<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contact request submitted by a visitor from a public tour.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $virtual_tour_id
 * @property int|null $property_id
 * @property int|null $tour_visit_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $message
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['name', 'email', 'phone', 'message', 'read_at'])]
class TourLead extends Model
{
    use HasPublicUlid;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<VirtualTour, $this>
     */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(VirtualTour::class, 'virtual_tour_id');
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
