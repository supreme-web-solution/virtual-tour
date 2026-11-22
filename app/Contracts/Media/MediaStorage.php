<?php

namespace App\Contracts\Media;

use App\Services\Media\StoredAsset;
use App\Services\Media\UploadSignature;

/**
 * Permanent media storage and delivery. Business logic depends on this
 * contract, never on a specific provider SDK.
 */
interface MediaStorage
{
    /**
     * Whether the provider has the credentials it needs.
     */
    public function isConfigured(): bool;

    /**
     * Sign a direct browser-to-storage upload into the given folder.
     */
    public function signUpload(string $folder): UploadSignature;

    /**
     * Copy a remote file (e.g. an AI provider's output) into permanent storage.
     * The storage provider fetches the URL itself; nothing passes through PHP memory.
     */
    public function uploadFromUrl(string $url, string $folder): StoredAsset;

    /**
     * Upload a local file (e.g. a downloaded clip or a rendered video).
     *
     * @param  'image'|'video'  $resourceType  Audio is stored under "video".
     */
    public function uploadFile(string $path, string $folder, string $resourceType): StoredAsset;

    /**
     * Fetch authoritative metadata for an uploaded asset, or null when it does not exist.
     */
    public function find(string $publicId, string $resourceType): ?StoredAsset;

    /**
     * Permanently delete an asset from storage.
     */
    public function delete(string $publicId, string $resourceType): void;

    /**
     * A small preview image URL (never modifies the original asset).
     */
    public function thumbnailUrl(StoredAsset $asset): ?string;

    /**
     * A delivery URL for an image scaled down (never cropped or upscaled) to
     * at most the given width, preserving its aspect ratio.
     */
    public function scaledImageUrl(string $publicId, int $maxWidth): string;

    /**
     * A delivery URL template that crops one tile out of the full-size image.
     * The placeholders {x}, {y}, {w} and {h} are pixel values filled in by the viewer.
     */
    public function tileUrlTemplate(string $publicId): string;

    /**
     * A flat, forward-facing view cut from the centre of an equirectangular
     * panorama, at the given aspect ratio (e.g. "16:9"). The original is untouched.
     */
    public function panoramaViewUrl(string $publicId, string $aspectRatio, int $width): string;
}
