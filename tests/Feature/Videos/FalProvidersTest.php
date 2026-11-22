<?php

use App\Exceptions\ProviderException;
use App\Services\Music\FalMusicProvider;
use App\Services\Video\FalVideoProvider;
use App\Services\Video\VideoClipRequest;
use App\Support\TemporaryFiles;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.fal.key' => 'test-fal-key',
        'ai.providers.fal.poll_seconds' => 1,
    ]);
});

function falQueue(string $app, string $id = 'req-123'): array
{
    return [
        'request_id' => $id,
        'status_url' => "https://queue.fal.run/{$app}/requests/{$id}/status",
        'response_url' => "https://queue.fal.run/{$app}/requests/{$id}",
    ];
}

function clipRequest(bool $audio = false): VideoClipRequest
{
    return new VideoClipRequest('Slow push towards the window.', 'https://storage.test/view/1280/lounge.jpg', 7, '16:9', withAudio: $audio);
}

test('a clip is submitted to the fal queue, waited for and downloaded', function () {
    Http::fake([
        'https://queue.fal.run/fal-ai/kling-video/v3/pro/image-to-video' => Http::response(falQueue('fal-ai/kling-video')),
        'https://queue.fal.run/fal-ai/kling-video/requests/req-123/status' => Http::sequence()
            ->push(['status' => 'IN_PROGRESS'])
            ->push(['status' => 'COMPLETED']),
        'https://queue.fal.run/fal-ai/kling-video/requests/req-123' => Http::response(['video' => ['url' => 'https://cdn.fal.media/out.mp4', 'content_type' => 'video/mp4']]),
        'https://cdn.fal.media/out.mp4' => Http::response(str_repeat('v', 4096)),
    ]);

    $result = app(FalVideoProvider::class)->generate(clipRequest(audio: true));

    expect(file_get_contents($result->path))->toHaveLength(4096)
        ->and($result->providerRequestId)->toBe('req-123')
        ->and($result->costUsd)->toBeGreaterThan(0);
    TemporaryFiles::delete($result->path);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://queue.fal.run/fal-ai/kling-video/v3/pro/image-to-video'
        && $request->hasHeader('Authorization', 'Key test-fal-key')
        && $request['start_image_url'] === 'https://storage.test/view/1280/lounge.jpg'
        && $request['duration'] === '7'
        && $request['generate_audio'] === true);
});

test('clip lengths are kept within what the model allows, and sound is off unless asked', function () {
    Http::fake([
        'https://queue.fal.run/fal-ai/kling-video/v3/pro/image-to-video' => Http::response(falQueue('fal-ai/kling-video')),
        '*/status' => Http::response(['status' => 'COMPLETED']),
        'https://queue.fal.run/fal-ai/kling-video/requests/req-123' => Http::response(['video' => ['url' => 'https://cdn.fal.media/out.mp4']]),
        'https://cdn.fal.media/*' => Http::response(str_repeat('v', 4096)),
    ]);

    $result = app(FalVideoProvider::class)->generate(new VideoClipRequest('Pan across the kitchen.', 'https://storage.test/a.jpg', 30, '9:16'));
    TemporaryFiles::delete($result->path);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'image-to-video')
        && $request['duration'] === '10'
        && $request['generate_audio'] === false);
});

test('a request that fails at fal is reported with its reason', function () {
    Http::fake([
        'https://queue.fal.run/fal-ai/kling-video/v3/pro/image-to-video' => Http::response(falQueue('fal-ai/kling-video')),
        '*/status' => Http::response(['status' => 'COMPLETED', 'error' => 'Content flagged by the safety checker', 'error_type' => 'content_policy_violation']),
    ]);

    expect(fn () => app(FalVideoProvider::class)->generate(clipRequest()))
        ->toThrow(ProviderException::class, 'Content flagged by the safety checker');
});

test('a wrong API key is explained and not retried', function () {
    Http::fake(['https://queue.fal.run/*' => Http::response(['detail' => 'Unauthorized'], 401)]);

    try {
        app(FalVideoProvider::class)->generate(clipRequest());
        $this->fail('Expected an exception.');
    } catch (ProviderException $exception) {
        expect($exception->getMessage())->toContain('FAL_KEY')->and($exception->retryable)->toBeFalse();
    }
});

test('busy responses from fal are retried later', function () {
    Http::fake(['https://queue.fal.run/*' => Http::response('', 503)]);

    try {
        app(FalVideoProvider::class)->generate(clipRequest());
        $this->fail('Expected an exception.');
    } catch (ProviderException $exception) {
        expect($exception->retryable)->toBeTrue();
    }
});

test('music is instrumental and as long as the video', function () {
    Http::fake([
        'https://queue.fal.run/fal-ai/elevenlabs/music' => Http::response(falQueue('fal-ai/elevenlabs', 'm-1')),
        '*/status' => Http::response(['status' => 'COMPLETED']),
        'https://queue.fal.run/fal-ai/elevenlabs/requests/m-1' => Http::response(['audio' => ['url' => 'https://cdn.fal.media/music.mp3', 'content_type' => 'audio/mpeg']]),
        'https://cdn.fal.media/music.mp3' => Http::response(str_repeat('a', 2048)),
    ]);

    $result = app(FalMusicProvider::class)->generate('Calm piano for a villa tour.', 18);
    TemporaryFiles::delete($result->path);

    expect($result->mimeType)->toBe('audio/mpeg');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'elevenlabs/music')
        && $request['force_instrumental'] === true
        && $request['music_length_ms'] === 18000);
});

test('without FAL_KEY the providers say they are not configured', function () {
    config(['services.fal.key' => null]);

    expect(app(FalVideoProvider::class)->isConfigured())->toBeFalse()
        ->and(fn () => app(FalMusicProvider::class)->generate('x'))->toThrow(ProviderException::class, 'fal.ai is not configured');
});
