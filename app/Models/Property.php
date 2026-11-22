<?php

namespace App\Models;

use App\Enums\AreaUnit;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $name
 * @property string $slug
 * @property PropertyType $property_type
 * @property PropertyStatus $status
 * @property string|null $description
 * @property string|null $address
 * @property string|null $city
 * @property string|null $region
 * @property string|null $country
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $bedrooms
 * @property string|null $bathrooms
 * @property string|null $area
 * @property AreaUnit $area_unit
 * @property string|null $price
 * @property string|null $currency
 * @property int|null $cover_asset_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'name', 'property_type', 'status', 'description', 'address', 'city', 'region', 'country',
    'latitude', 'longitude', 'bedrooms', 'bathrooms', 'area', 'area_unit', 'price', 'currency',
    'cover_asset_id', 'metadata',
])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory, HasPublicUlid, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'status' => PropertyStatus::class,
            'area_unit' => AreaUnit::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'bathrooms' => 'decimal:1',
            'area' => 'decimal:2',
            'price' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (Property $property): void {
            if (blank($property->slug)) {
                $property->slug = static::uniqueSlugFor($property->workspace_id, $property->name);
            }
        });
    }

    /**
     * Generate a slug that is unique within the workspace (soft-deleted rows included).
     */
    public static function uniqueSlugFor(int $workspaceId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'property';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function coverAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'cover_asset_id');
    }

    /**
     * @return HasMany<MediaAsset, $this>
     */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /**
     * @return HasMany<VirtualTour, $this>
     */
    public function tours(): HasMany
    {
        return $this->hasMany(VirtualTour::class);
    }

    /**
     * Filter by a free-text search across name and location fields.
     *
     * @param  Builder<Property>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('name', 'like', $like)
                ->orWhere('address', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('country', 'like', $like);
        });
    }

    /**
     * A single-line location summary, e.g. "Lekki, Lagos, Nigeria".
     */
    public function locationLabel(): ?string
    {
        $parts = array_filter([$this->city, $this->region, $this->country]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
