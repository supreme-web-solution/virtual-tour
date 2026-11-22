<?php

namespace App\Services\Media;

use App\Contracts\Media\MediaStorage;
use App\Support\ProviderConfiguration;
use Cloudinary\Api\ApiUtils;
use Cloudinary\Api\Exception\NotFound;
use Cloudinary\Cloudinary;
use RuntimeException;

class CloudinaryMediaStorage implements MediaStorage
{
    /**
     * Files larger than this are sent to Cloudinary in chunks of this size.
     */
    protected const CHUNK_SIZE = 20 * 1024 * 1024;

    /**
     * Formats Cloudinary stores under the "video" resource type that are audio-only.
     */
    protected const AUDIO_FORMATS = ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac'];

    protected ?Cloudinary $client = null;

    public function __construct(protected ProviderConfiguration $providers) {}

    public function isConfigured(): bool
    {
        return $this->providers->cloudinary();
    }

    public function signUpload(string $folder): UploadSignature
    {
        // Cloudinary assigns a random public ID inside the folder by default.
        $params = [
            'folder' => $folder,
            'timestamp' => time(),
        ];

        $params['signature'] = ApiUtils::signParameters($params, $this->config('api_secret'));
        $params['api_key'] = $this->config('api_key');

        return new UploadSignature(
            uploadUrl: "https://api.cloudinary.com/v1_1/{$this->config('cloud_name')}/auto/upload",
            fields: $params,
            chunkSize: self::CHUNK_SIZE,
        );
    }

    public function uploadFromUrl(string $url, string $folder): StoredAsset
    {
        $response = $this->client()->uploadApi()->upload($url, [
            'folder' => $folder,
            'resource_type' => 'image',
        ]);

        return new StoredAsset(
            publicId: (string) $response['public_id'],
            resourceType: (string) $response['resource_type'],
            format: strtolower((string) ($response['format'] ?? '')),
            bytes: (int) ($response['bytes'] ?? 0),
            secureUrl: (string) $response['secure_url'],
            width: isset($response['width']) ? (int) $response['width'] : null,
            height: isset($response['height']) ? (int) $response['height'] : null,
        );
    }

    public function uploadFile(string $path, string $folder, string $resourceType): StoredAsset
    {
        $options = ['folder' => $folder, 'resource_type' => $resourceType];

        // The SDK sends large files in chunks automatically.
        $response = $this->client()->uploadApi()->upload($path, $options);

        $format = strtolower((string) ($response['format'] ?? ''));

        return new StoredAsset(
            publicId: (string) $response['public_id'],
            resourceType: (string) $response['resource_type'],
            format: $format,
            bytes: (int) ($response['bytes'] ?? 0),
            secureUrl: (string) $response['secure_url'],
            width: isset($response['width']) ? (int) $response['width'] : null,
            height: isset($response['height']) ? (int) $response['height'] : null,
            duration: isset($response['duration']) ? (float) $response['duration'] : null,
            isAudio: $response['resource_type'] === 'video' && in_array($format, self::AUDIO_FORMATS, true),
        );
    }

    public function find(string $publicId, string $resourceType): ?StoredAsset
    {
        try {
            $response = $this->client()->adminApi()->asset($publicId, [
                'resource_type' => $resourceType,
                'media_metadata' => true,
            ]);
        } catch (NotFound) {
            return null;
        }

        $format = strtolower((string) ($response['format'] ?? ''));

        return new StoredAsset(
            publicId: (string) $response['public_id'],
            resourceType: (string) $response['resource_type'],
            format: $format,
            bytes: (int) ($response['bytes'] ?? 0),
            secureUrl: (string) $response['secure_url'],
            width: isset($response['width']) ? (int) $response['width'] : null,
            height: isset($response['height']) ? (int) $response['height'] : null,
            duration: isset($response['duration']) ? (float) $response['duration'] : null,
            isAudio: $response['resource_type'] === 'video'
                && (in_array($format, self::AUDIO_FORMATS, true) || ($response['is_audio'] ?? false) === true),
        );
    }

    public function delete(string $publicId, string $resourceType): void
    {
        $this->client()->uploadApi()->destroy($publicId, [
            'resource_type' => $resourceType,
            'invalidate' => true,
        ]);
    }

    public function thumbnailUrl(StoredAsset $asset): ?string
    {
        $base = "https://res.cloudinary.com/{$this->config('cloud_name')}";
        $publicId = implode('/', array_map('rawurlencode', explode('/', $asset->publicId)));

        return match (true) {
            $asset->isAudio => null,
            $asset->resourceType === 'video' => "{$base}/video/upload/c_fill,w_640,h_400,so_0,q_auto/{$publicId}.jpg",
            default => "{$base}/image/upload/c_fill,w_640,h_400,g_auto,f_auto,q_auto/{$publicId}",
        };
    }

    public function scaledImageUrl(string $publicId, int $maxWidth): string
    {
        $publicId = implode('/', array_map('rawurlencode', explode('/', $publicId)));

        // c_limit only ever shrinks; the 2:1 geometry of panoramas is preserved.
        return "https://res.cloudinary.com/{$this->config('cloud_name')}/image/upload/c_limit,w_{$maxWidth},q_auto:good/{$publicId}.jpg";
    }

    public function tileUrlTemplate(string $publicId): string
    {
        $publicId = implode('/', array_map('rawurlencode', explode('/', $publicId)));

        return "https://res.cloudinary.com/{$this->config('cloud_name')}/image/upload/c_crop,x_{x},y_{y},w_{w},h_{h}/q_auto:good/{$publicId}.jpg";
    }

    public function panoramaViewUrl(string $publicId, string $aspectRatio, int $width): string
    {
        $publicId = implode('/', array_map('rawurlencode', explode('/', $publicId)));
        $aspect = preg_match('/^\d{1,2}:\d{1,2}$/', $aspectRatio) ? $aspectRatio : '16:9';

        // The middle ~30% of an equirectangular image is roughly a 100° forward view,
        // where horizontal distortion is lowest.
        return "https://res.cloudinary.com/{$this->config('cloud_name')}/image/upload/c_crop,w_0.3,ar_{$aspect},g_center/c_scale,w_{$width}/q_auto:good/{$publicId}.jpg";
    }

    protected function client(): Cloudinary
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cloudinary is not configured. Set CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET.');
        }

        return $this->client ??= new Cloudinary([
            'cloud' => [
                'cloud_name' => $this->config('cloud_name'),
                'api_key' => $this->config('api_key'),
                'api_secret' => $this->config('api_secret'),
            ],
            'url' => ['secure' => true],
        ]);
    }

    protected function config(string $key): string
    {
        return (string) config("services.cloudinary.{$key}");
    }
}
