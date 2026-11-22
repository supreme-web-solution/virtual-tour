<?php

namespace Tests\Fakes;

use App\Contracts\Media\MediaStorage;
use App\Services\Media\StoredAsset;
use App\Services\Media\UploadSignature;

/**
 * In-memory stand-in for the storage provider. Tests seed the assets that
 * "exist" in storage and inspect what was deleted.
 */
class FakeMediaStorage implements MediaStorage
{
    /** @var array<string, StoredAsset> */
    public array $assets = [];

    /** @var list<string> */
    public array $deleted = [];

    public bool $configured = true;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function signUpload(string $folder): UploadSignature
    {
        return new UploadSignature(
            uploadUrl: 'https://storage.test/upload',
            fields: ['folder' => $folder, 'timestamp' => 1, 'signature' => 'fake', 'api_key' => 'key'],
            chunkSize: 1024,
        );
    }

    /** @var list<string> */
    public array $fetchedUrls = [];

    public function uploadFromUrl(string $url, string $folder): StoredAsset
    {
        $this->fetchedUrls[] = $url;
        $publicId = $this->put($folder.'/generated-'.count($this->fetchedUrls), width: 4096, height: 2048, bytes: 2_000_000);

        return $this->assets[$publicId];
    }

    /** @var list<string> */
    public array $uploadedFiles = [];

    public function uploadFile(string $path, string $folder, string $resourceType): StoredAsset
    {
        $this->uploadedFiles[] = $path;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'bin';
        $publicId = $folder.'/file-'.count($this->uploadedFiles);
        $isAudio = in_array($extension, ['mp3', 'wav', 'm4a'], true);

        $this->assets[$publicId] = new StoredAsset(
            publicId: $publicId,
            resourceType: $resourceType,
            format: $extension,
            bytes: (int) (is_file($path) ? filesize($path) : 1000),
            secureUrl: "https://storage.test/{$publicId}.{$extension}",
            width: $resourceType === 'video' && ! $isAudio ? 1280 : ($resourceType === 'image' ? 1280 : null),
            height: $resourceType === 'video' && ! $isAudio ? 720 : ($resourceType === 'image' ? 720 : null),
            duration: $resourceType === 'video' ? 6.0 : null,
            isAudio: $isAudio,
        );

        return $this->assets[$publicId];
    }

    public function find(string $publicId, string $resourceType): ?StoredAsset
    {
        $asset = $this->assets[$publicId] ?? null;

        return $asset?->resourceType === $resourceType ? $asset : null;
    }

    public function delete(string $publicId, string $resourceType): void
    {
        $this->deleted[] = $publicId;
        unset($this->assets[$publicId]);
    }

    public function thumbnailUrl(StoredAsset $asset): ?string
    {
        return $asset->isAudio ? null : "https://storage.test/thumb/{$asset->publicId}";
    }

    public function scaledImageUrl(string $publicId, int $maxWidth): string
    {
        return "https://storage.test/w{$maxWidth}/{$publicId}.jpg";
    }

    public function tileUrlTemplate(string $publicId): string
    {
        return "https://storage.test/tiles/{$publicId}/{x}_{y}_{w}_{h}.jpg";
    }

    public function panoramaViewUrl(string $publicId, string $aspectRatio, int $width): string
    {
        return "https://storage.test/view/{$width}/{$publicId}.jpg";
    }

    /**
     * Pretend a file was uploaded and return its public ID.
     */
    public function put(
        string $publicId,
        string $format = 'jpg',
        string $resourceType = 'image',
        ?int $width = 4000,
        ?int $height = 3000,
        int $bytes = 1_000_000,
        bool $isAudio = false,
    ): string {
        $this->assets[$publicId] = new StoredAsset(
            publicId: $publicId,
            resourceType: $resourceType,
            format: $format,
            bytes: $bytes,
            secureUrl: "https://storage.test/{$publicId}.{$format}",
            width: $width,
            height: $height,
            isAudio: $isAudio,
        );

        return $publicId;
    }
}
