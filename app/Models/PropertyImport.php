<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\PropertyImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A listing page a user asked to import: what was found on it, which
 * photos they picked, and the property it became.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int|null $user_id
 * @property int|null $ai_generation_id
 * @property int|null $property_id
 * @property int|null $virtual_tour_id Tour being built from this import, if any
 * @property string $url
 * @property string $source_host
 * @property string $status queued|fetching|ready|importing|completed|failed
 * @property string|null $page_title
 * @property array<string, mixed>|null $details
 * @property list<array{url: string, alt: string|null}>|null $images
 * @property list<string>|null $selected_images
 * @property int $imported_count
 * @property int $failed_count
 * @property string|null $error
 * @property Carbon|null $rights_confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Property|null $property
 * @property-read AiGeneration|null $generation
 * @property-read Workspace $workspace
 * @property-read User|null $user
 */
#[Fillable(['url', 'source_host', 'status', 'page_title', 'details', 'images', 'selected_images', 'imported_count', 'failed_count', 'error', 'rights_confirmed_at'])]
class PropertyImport extends Model
{
    /** @use HasFactory<PropertyImportFactory> */
    use HasFactory, HasPublicUlid;

    public const ACTIVE_STATUSES = ['queued', 'fetching', 'importing'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'images' => 'array',
            'selected_images' => 'array',
            'rights_confirmed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<VirtualTour, $this>
     */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(VirtualTour::class, 'virtual_tour_id');
    }

    /**
     * @return BelongsTo<AiGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'ai_generation_id');
    }
}
