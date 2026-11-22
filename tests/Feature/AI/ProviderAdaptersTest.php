<?php

use App\Exceptions\ProviderException;
use App\Services\AI\OpenAIProvider;
use App\Services\AI\StructuredRequest;
use App\Services\Panorama\PanoramaStatus;
use App\Services\Panorama\SkyboxPanoramaProvider;
use App\Support\ProviderConfiguration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.openai.api_key' => 'sk-test',
        'services.skybox.api_key' => 'sky-test',
        'services.skybox.webhook_secret' => 'whsec-test',
    ]);
});

function structuredRequest(): StructuredRequest
{
    return new StructuredRequest(
        model: 'gpt-6-luna',
        instructions: 'Plan scenes',
        prompt: 'A villa',
        schemaName: 'scene_plan',
        schema: ['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
        imageUrls: ['https://img.test/1.jpg'],
    );
}

function openAiResponse(array $content, string $status = 'completed'): array
{
    return [
        'id' => 'resp_1', 'status' => $status, 'model' => 'gpt-6-luna',
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => $content]],
        'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000],
    ];
}

test('OpenAI requests use a strict JSON schema, images and no storage', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiResponse([['type' => 'output_text', 'text' => '{"a":"ok"}']]))]);

    $result = (new OpenAIProvider)->generate(structuredRequest());

    expect($result->data)->toBe(['a' => 'ok'])
        ->and($result->costUsd)->toBe(0.6)
        ->and($result->providerRequestId)->toBe('resp_1');

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $body['store'] === false
            && $body['text']['format']['type'] === 'json_schema'
            && $body['text']['format']['strict'] === true
            && $body['input'][1]['content'][1] === ['type' => 'input_image', 'image_url' => 'https://img.test/1.jpg', 'detail' => 'low'];
    });
});

test('provider cost is recorded for model names that contain dots', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiResponse([['type' => 'output_text', 'text' => '{"a":"ok"}']]))]);

    $request = new StructuredRequest(
        model: 'gpt-6.1-terra',
        instructions: 'Analyse photos',
        prompt: 'One photo',
        schemaName: 'analysis',
        schema: ['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
    );

    // 1M input tokens at $1.00 plus 1M output tokens at $5.00.
    expect((new OpenAIProvider)->generate($request)->costUsd)->toBe(6.0);
});

test('OpenAI refusals, incomplete output and rate limits are reported clearly', function () {
    Http::fakeSequence('api.openai.com/*')
        ->push(openAiResponse([['type' => 'refusal', 'refusal' => 'Not allowed']]))
        ->push(openAiResponse([], 'incomplete') + ['incomplete_details' => ['reason' => 'max_output_tokens']])
        ->push(['error' => ['message' => 'Slow down']], 429);

    $provider = new OpenAIProvider;

    expect(fn () => $provider->generate(structuredRequest()))->toThrow(fn (ProviderException $e) => expect($e->retryable)->toBeFalse() && expect($e->getMessage())->toContain('declined'));
    expect(fn () => $provider->generate(structuredRequest()))->toThrow(ProviderException::class, 'incomplete');
    expect(fn () => $provider->generate(structuredRequest()))->toThrow(fn (ProviderException $e) => expect($e->retryable)->toBeTrue());
});

test('Skybox text and photo generations send only documented fields', function () {
    Http::fake(['backend.blockadelabs.com/*' => Http::response(['id' => 42, 'status' => 'pending', 'credit_cost' => 3, 'model' => 'Model 4'])]);
    $provider = new SkyboxPanoramaProvider(app(ProviderConfiguration::class));

    $status = $provider->generate('A loft', 'people', 155, 'https://app.test/webhooks/skybox');
    $provider->convertImageToPanorama('https://img.test/room.jpg', 'A loft', null, 155, null);

    expect($status)->requestId->toBe('42')->state->toBe(PanoramaStatus::QUEUED)->providerCredits->toBe(3);

    Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', 'sky-test')
        && $request->url() === 'https://backend.blockadelabs.com/api/v1/skybox'
        && $request->data() === ['skybox_style_id' => 155, 'prompt' => 'A loft', 'negative_text' => 'people', 'webhook_url' => 'https://app.test/webhooks/skybox']);

    Http::assertSent(fn (Request $request) => ($request->data()['image_to_pano_init_image'] ?? null) === 'https://img.test/room.jpg'
        && ! array_key_exists('variant_id', $request->data())
        && ! array_key_exists('negative_text', $request->data()));
});

test('Skybox statuses are normalised and access errors explained', function () {
    Http::fakeSequence('backend.blockadelabs.com/*')
        ->push(['request' => ['id' => 42, 'status' => 'complete', 'file_url' => 'https://b.test/pano.jpg']])
        ->push(['request' => ['id' => 43, 'status' => 'error', 'error_message' => 'NSFW']])
        ->push(['error' => 'Access denied'], 403);

    $provider = new SkyboxPanoramaProvider(app(ProviderConfiguration::class));

    expect($provider->getStatus('42'))->state->toBe(PanoramaStatus::COMPLETED)->fileUrl->toBe('https://b.test/pano.jpg');
    expect($provider->getStatus('43'))->state->toBe(PanoramaStatus::FAILED)->errorMessage->toBe('NSFW');
    expect(fn () => $provider->getStatus('44'))->toThrow(ProviderException::class, 'Standard or Business');
});

test('Skybox webhooks require a valid, recent signature', function () {
    $provider = new SkyboxPanoramaProvider(app(ProviderConfiguration::class));
    $body = json_encode(['id' => 42, 'status' => 'complete', 'file_url' => 'https://b.test/pano.jpg']);
    $signature = hash_hmac('sha256', $body, 'whsec-test');

    expect($provider->parseWebhook($body, ['x-signature' => $signature, 'x-timestamp' => (string) time()]))
        ->state->toBe(PanoramaStatus::COMPLETED);
    expect($provider->parseWebhook($body, ['x-signature' => 'forged', 'x-timestamp' => (string) time()]))->toBeNull();
    expect($provider->parseWebhook($body, ['x-signature' => $signature, 'x-timestamp' => (string) (time() - 3600)]))->toBeNull();
    expect($provider->parseWebhook($body.' ', ['x-signature' => $signature, 'x-timestamp' => (string) time()]))->toBeNull();
});
