<?php

namespace App\Models;

use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Jobs\Media\WarmPanoramaVersions;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Panorama\PanoramaValidationService;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int|null $property_id
 * @property int|null $uploaded_by
 * @property int|null $parent_asset_id
 * @property AssetType $asset_type
 * @property AssetSource $source_type
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property string|null $format
 * @property int $file_size
 * @property int|null $width
 * @property int|null $height
 * @property string|null $duration
 * @property string $cloudinary_public_id
 * @property string $cloudinary_resource_type
 * @property string $secure_url
 * @property string|null $thumbnail_url
 * @property string|null $provider
 * @property int|null $generation_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'property_id', 'uploaded_by', 'parent_asset_id', 'asset_type', 'source_type', 'original_filename',
    'mime_type', 'format', 'file_size', 'width', 'height', 'duration', 'cloudinary_public_id',
    'cloudinary_resource_type', 'secure_url', 'thumbnail_url', 'provider', 'generation_id', 'metadata',
])]
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory, HasPublicUlid;

    protected static function booted(): void
    {
        static::created(function (MediaAsset $asset): void {
            if (filled(config('services.cloudinary.cloud_name')) && $asset->isImage() && $asset->isEquirectangular()) {
                WarmPanoramaVersions::dispatch($asset->id)->afterCommit();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asset_type' => AssetType::class,
            'source_type' => AssetSource::class,
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'decimal:3',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'parent_asset_id');
    }

    /**
     * Tour scenes that display this asset as their panorama.
     *
     * @return HasMany<TourScene, $this>
     */
    public function tourScenes(): HasMany
    {
        return $this->hasMany(TourScene::class);
    }

    /**
     * Whether the asset is a displayable still image.
     */
    public function isImage(): bool
    {
        return $this->cloudinary_resource_type === 'image';
    }

    /**
     * Whether this is a full 360° equirectangular panorama. Trusts the stored
     * check when there is one, otherwise judges by the 2:1 shape, so a
     * panorama uploaded as a plain photo is recognised too.
     */
    public function isEquirectangular(): bool
    {
        if (isset($this->metadata['panorama']['is_equirectangular'])) {
            return (bool) $this->metadata['panorama']['is_equirectangular'];
        }

        return (bool) app(PanoramaValidationService::class)
            ->inspect($this->width, $this->height)['is_equirectangular'];
    }
}
