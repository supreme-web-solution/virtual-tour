<?php

namespace App\Services\StreetView;

use App\Contracts\Media\MediaStorage;
use App\Enums\AssetSource;
use App\Exceptions\ProviderException;
use App\Jobs\PublishToStreetView;
use App\Models\GoogleConnection;
use App\Models\StreetViewPublication;
use App\Models\TourScene;
use App\Services\Google\GoogleOAuth;
use App\Support\TemporaryFiles;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Publishes a scene's own 360° photo to Google Street View through the
 * Street View Publish API, using the workspace's connected Google account.
 *
 * Street View is for real photography: AI-made, enhanced or imported images
 * are never sent, and a scene needs a map pin for its position.
 *
 * @see https://developers.google.com/streetview/publish/reference/rest
 */
class StreetViewPublisher
{
    protected const API = 'https://streetviewpublish.googleapis.com/v1';

    protected const MAX_BYTES = 75 * 1024 * 1024;

    public function __construct(
        protected GoogleOAuth $oauth,
        protected MediaStorage $storage,
        protected PanoramaXmp $xmp,
    ) {}

    /**
     * Why a scene can't be published, or null when it can.
     */
    public function problem(TourScene $scene): ?string
    {
        $asset = $scene->mediaAsset;

        $problem = match (true) {
            ! ($asset->metadata['panorama']['is_equirectangular'] ?? false) => __('Not a 360° panorama.'),
            $asset->source_type !== AssetSource::Upload || ($asset->metadata['ai']['synthetic'] ?? false) => __('Only your own uploaded photos can go on Street View, not AI-made or imported images.'),
            (int) $asset->width < (int) config('services.google.min_panorama_width') => __('Too small: Street View needs at least :width px wide.', ['width' => config('services.google.min_panorama_width')]),
            $scene->latitude === null || $scene->longitude === null => __('Place this scene on the map first.'),
            default => null,
        };

        return is_string($problem) ? $problem : null;
    }

    /**
     * Mark a scene as publishing and queue the upload.
     */
    public function queue(TourScene $scene, int $workspaceId): StreetViewPublication
    {
        $publication = StreetViewPublication::where('tour_scene_id', $scene->id)->first() ?? new StreetViewPublication;
        $publication->tour_scene_id = $scene->id;
        $publication->workspace_id = $workspaceId;
        $publication->status = StreetViewPublication::PENDING;
        $publication->error = null;
        $publication->save();

        PublishToStreetView::dispatch($publication)->afterCommit();

        return $publication;
    }

    public function run(StreetViewPublication $publication): void
    {
        $scene = $publication->scene()->with('mediaAsset')->firstOrFail();
        $problem = $this->problem($scene);

        if ($problem !== null) {
            $this->fail($publication, $problem);

            return;
        }

        $path = TemporaryFiles::path('jpg');

        try {
            $token = $this->token($publication->workspace_id);
            $asset = $scene->mediaAsset;
            $this->download($asset->cloudinary_public_id, (int) $asset->width, (int) $asset->height, $path);

            $uploadUrl = $this->request($token)->post(self::API.'/photo:startUpload')->throw()->json('uploadUrl');

            if (! is_string($uploadUrl) || $uploadUrl === '') {
                throw new ProviderException('Google did not start the upload.', retryable: true);
            }

            $this->request($token)->timeout(300)->withBody((string) file_get_contents($path), 'image/jpeg')->post($uploadUrl)->throw();

            $photo = $this->request($token)->post(self::API.'/photo', [
                'uploadReference' => ['uploadUrl' => $uploadUrl],
                'pose' => ['latLngPair' => ['latitude' => (float) $scene->latitude, 'longitude' => (float) $scene->longitude]],
                'captureTime' => $asset->created_at?->toIso8601ZuluString(),
            ])->throw()->json();

            $publication->forceFill([
                'status' => StreetViewPublication::PUBLISHED,
                'photo_id' => $photo['photoId']['id'] ?? null,
                'share_link' => $photo['shareLink'] ?? null,
                'error' => null,
                'published_at' => now(),
            ])->save();
        } catch (ProviderException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            $this->fail($publication, $exception->getMessage());
        } catch (ConnectionException) {
            $this->fail($publication, 'Could not reach Google. Try again in a moment.');
        } catch (RequestException $exception) {
            $this->fail($publication, $this->describe($exception));
        } finally {
            TemporaryFiles::delete($path);
        }
    }

    /**
     * Take the photo off Street View and forget it here.
     */
    public function unpublish(StreetViewPublication $publication): void
    {
        if ($publication->photo_id !== null) {
            $response = $this->request($this->token($publication->workspace_id))->delete(self::API.'/photo/'.rawurlencode($publication->photo_id));

            // Already gone at Google is fine.
            if ($response->failed() && $response->status() !== 404) {
                throw new ProviderException($this->describe($response->toException()), status: $response->status());
            }
        }

        $publication->delete();
    }

    protected function token(int $workspaceId): string
    {
        $connection = GoogleConnection::where('workspace_id', $workspaceId)->first();

        if ($connection === null) {
            throw new ProviderException('Connect your Google account first.');
        }

        return $this->oauth->accessToken($connection->refresh_token);
    }

    protected function request(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->asJson()->timeout(60);
    }

    /**
     * The original photo as a JPEG with the Photo Sphere metadata added.
     */
    protected function download(string $publicId, int $width, int $height, string $path): void
    {
        $url = $this->storage->scaledImageUrl($publicId, $width);

        if (! str_starts_with($url, 'https://')) {
            throw new ProviderException('The photo could not be loaded.');
        }

        $response = Http::timeout(120)->get($url);
        $bytes = $response->successful() ? $response->body() : '';

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ProviderException($bytes === '' ? 'The photo could not be loaded.' : 'The photo is larger than the 75 MB Street View limit.', retryable: $bytes === '');
        }

        $size = @getimagesizefromstring($bytes);
        file_put_contents($path, $this->xmp->embed($bytes, (int) ($size[0] ?? $width), (int) ($size[1] ?? $height)));
    }

    protected function fail(StreetViewPublication $publication, string $message): void
    {
        $publication->forceFill(['status' => StreetViewPublication::FAILED, 'error' => mb_substr($message, 0, 500)])->save();
    }

    protected function describe(RequestException $exception): string
    {
        $status = $exception->response->status();
        $message = (string) $exception->response->json('error.message');

        return match (true) {
            $status === 401 || $status === 403 => 'Google refused access. Reconnect Google, and check the Street View Publish API is enabled for your project.',
            $status === 429 => 'Google is limiting requests right now. Try again later.',
            $message !== '' => 'Google said: '.$message,
            default => "Google could not publish this photo ({$status}).",
        };
    }
}
