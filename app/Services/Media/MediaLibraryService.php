<?php

namespace App\Services\Media;

use App\Contracts\Media\MediaStorage;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\Entitlement;
use App\Exceptions\PlanLimitReachedException;
use App\Jobs\Media\DeleteStoredMedia;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Services\Billing\EntitlementService;
use App\Services\Panorama\PanoramaValidationService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaLibraryService
{
    public function __construct(
        protected MediaStorage $storage,
        protected PanoramaValidationService $panoramas,
        protected ActivityLogger $activity,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * Register a file the browser uploaded directly to storage.
     *
     * The storage provider is the source of truth for format, size and
     * dimensions; nothing the browser reports about the file is trusted.
     * Invalid uploads are removed from storage before failing.
     *
     * @throws ValidationException
     */
    public function registerUpload(
        Workspace $workspace,
        User $user,
        string $publicId,
        string $resourceType,
        AssetType $assetType,
        ?int $propertyId,
        ?string $originalFilename,
    ): MediaAsset {
        if (! Str::startsWith($publicId, $workspace->storageFolder().'/')) {
            throw ValidationException::withMessages(['public_id' => __('This upload does not belong to your workspace.')]);
        }

        if (MediaAsset::where('cloudinary_public_id', $publicId)->exists()) {
            throw ValidationException::withMessages(['public_id' => __('This file has already been added to the library.')]);
        }

        $stored = $this->storage->find($publicId, $resourceType);

        if ($stored === null) {
            throw ValidationException::withMessages(['public_id' => __('The uploaded file could not be found in storage. Please upload it again.')]);
        }

        try {
            $this->entitlements->ensureCanAdd($workspace->id, Entitlement::StorageMb, $stored->bytes);
        } catch (PlanLimitReachedException $exception) {
            DeleteStoredMedia::dispatch($stored->publicId, $stored->resourceType);

            throw $exception;
        }

        $assetType = $this->resolveType($assetType, $stored);

        if ($error = $this->validationError($assetType, $stored)) {
            DeleteStoredMedia::dispatch($stored->publicId, $stored->resourceType);

            throw ValidationException::withMessages(['file' => $error]);
        }

        $metadata = [];

        if ($assetType === AssetType::Panorama) {
            $metadata['panorama'] = $this->panoramas->inspect($stored->width, $stored->height);
        }

        $asset = new MediaAsset([
            'property_id' => $propertyId,
            'uploaded_by' => $user->id,
            'asset_type' => $assetType,
            'source_type' => AssetSource::Upload,
            'original_filename' => $originalFilename ? Str::limit(basename($originalFilename), 250, '') : null,
            'mime_type' => $stored->mimeType(),
            'format' => $stored->format,
            'file_size' => $stored->bytes,
            'width' => $stored->width,
            'height' => $stored->height,
            'duration' => $stored->duration,
            'cloudinary_public_id' => $stored->publicId,
            'cloudinary_resource_type' => $stored->resourceType,
            'secure_url' => $stored->secureUrl,
            'thumbnail_url' => $this->storage->thumbnailUrl($stored),
            'provider' => 'cloudinary',
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
        $asset->workspace_id = $workspace->id;
        $asset->save();

        $this->activity->log($workspace->id, $user, 'media.uploaded', "Uploaded {$assetType->label()} {$asset->original_filename}", $asset);

        return $asset;
    }

    /**
     * Register an image this server downloaded and stored (URL import).
     * Equirectangular 2:1 images become panoramas; everything else a photo.
     *
     * @param  array<string, mixed>  $source  Where the file came from (kept for attribution).
     *
     * @throws ValidationException
     */
    public function registerImported(Workspace $workspace, User $user, StoredAsset $stored, ?int $propertyId, ?string $filename, array $source): MediaAsset
    {
        $ratio = $stored->width && $stored->height ? $stored->width / $stored->height : 0;
        $assetType = abs($ratio - 2) < 0.02 && (int) $stored->width >= 4000 ? AssetType::Panorama : AssetType::Photo;

        if ($error = $this->validationError($assetType, $stored)) {
            DeleteStoredMedia::dispatch($stored->publicId, $stored->resourceType);

            throw ValidationException::withMessages(['file' => $error]);
        }

        $metadata = ['import' => $source];

        if ($assetType === AssetType::Panorama) {
            $metadata['panorama'] = $this->panoramas->inspect($stored->width, $stored->height);
        }

        $asset = new MediaAsset([
            'property_id' => $propertyId,
            'uploaded_by' => $user->id,
            'asset_type' => $assetType,
            'source_type' => AssetSource::Imported,
            'original_filename' => $filename ? Str::limit(basename($filename), 250, '') : null,
            'mime_type' => $stored->mimeType(),
            'format' => $stored->format,
            'file_size' => $stored->bytes,
            'width' => $stored->width,
            'height' => $stored->height,
            'cloudinary_public_id' => $stored->publicId,
            'cloudinary_resource_type' => $stored->resourceType,
            'secure_url' => $stored->secureUrl,
            'thumbnail_url' => $this->storage->thumbnailUrl($stored),
            'provider' => 'cloudinary',
            'metadata' => $metadata,
        ]);
        $asset->workspace_id = $workspace->id;
        $asset->save();

        return $asset;
    }

    /**
     * Delete the library record now and remove the stored file in the background.
     */
    public function delete(MediaAsset $asset, ?User $user): void
    {
        $asset->delete();

        DeleteStoredMedia::dispatch($asset->cloudinary_public_id, $asset->cloudinary_resource_type);

        $this->activity->log($asset->workspace_id, $user, 'media.deleted', "Deleted {$asset->asset_type->label()} {$asset->original_filename}", $asset);
    }

    /**
     * Audio files arrive under the "video" resource family; classify them by content.
     */
    protected function resolveType(AssetType $requested, StoredAsset $stored): AssetType
    {
        if ($stored->isAudio) {
            return AssetType::Audio;
        }

        if ($requested === AssetType::Audio && $stored->resourceType === 'video') {
            return AssetType::Video;
        }

        return $requested;
    }

    protected function validationError(AssetType $type, StoredAsset $stored): ?string
    {
        if ($stored->resourceType !== $type->resourceType()) {
            return __('This file is not a valid :type.', ['type' => strtolower($type->label())]);
        }

        if (! in_array($stored->format, $type->allowedFormats(), true)) {
            return __('Unsupported format ":format". Allowed: :allowed.', [
                'format' => $stored->format,
                'allowed' => implode(', ', $type->allowedFormats()),
            ]);
        }

        if ($stored->bytes > $type->maxBytes()) {
            return __('The file is larger than the :size MB limit.', ['size' => intdiv($type->maxBytes(), 1024 * 1024)]);
        }

        if ($type->resourceType() === 'image' && (! $stored->width || ! $stored->height)) {
            return __('The image appears to be corrupted: its dimensions could not be read.');
        }

        return null;
    }
}
