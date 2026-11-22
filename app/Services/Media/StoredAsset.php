<?php

namespace App\Services\Media;

/**
 * Provider-neutral description of an asset as reported by the storage
 * provider (not by the browser), used to verify uploads.
 */
final readonly class StoredAsset
{
    public function __construct(
        public string $publicId,
        public string $resourceType,
        public string $format,
        public int $bytes,
        public string $secureUrl,
        public ?int $width = null,
        public ?int $height = null,
        public ?float $duration = null,
        public bool $isAudio = false,
    ) {}

    /**
     * A MIME type derived from the provider-detected format.
     */
    public function mimeType(): string
    {
        $format = strtolower($this->format);

        return match (true) {
            in_array($format, ['jpg', 'jpeg'], true) => 'image/jpeg',
            in_array($format, ['heic', 'heif'], true) => "image/{$format}",
            $format === 'mp3' => 'audio/mpeg',
            $format === 'wav' => 'audio/wav',
            $format === 'm4a' => 'audio/mp4',
            $format === 'mov' => 'video/quicktime',
            $this->resourceType === 'image' => "image/{$format}",
            default => "video/{$format}",
        };
    }
}
