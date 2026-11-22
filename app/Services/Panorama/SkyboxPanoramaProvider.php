<?php

namespace App\Services\Panorama;

use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Blockade Labs Skybox AI.
 *
 * Text-to-panorama and Image2Pano share POST /skybox; Image2Pano needs a
 * Model 4 style and `image_to_pano_init_image`, and rejects extra options
 * such as variant_id, so only documented fields are sent.
 *
 * @see https://docs.blockadelabs.com/api/skybox.html
 */
class SkyboxPanoramaProvider implements PanoramaGenerationProvider
{
    public function name(): string
    {
        return 'skybox';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.skybox.api_key'));
    }

    public function styles(bool $imageToPanorama = false): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $styles = Cache::remember('skybox:styles', now()->addHours(6), function (): array {
            $response = $this->send(fn (PendingRequest $http) => $http->get('/skybox/styles'));
            $items = $response->json('data') ?? $response->json() ?? [];

            return array_values(array_map(fn (array $style): array => [
                'id' => (int) $style['id'],
                'name' => (string) ($style['name'] ?? 'Style '.$style['id']),
                'model' => (string) ($style['model'] ?? ''),
                'model_version' => (string) ($style['model_version'] ?? ''),
                'max_prompt_length' => (int) ($style['max-char'] ?? 2000),
                'max_negative_length' => (int) ($style['negative-text-max-char'] ?? 600),
                'preview_url' => $style['image'] ?? null,
            ], is_array($items) ? array_filter($items, 'is_array') : []));
        });

        $list = array_map(fn (array $style): PanoramaStyle => new PanoramaStyle(
            id: $style['id'],
            name: $style['name'],
            model: $style['model'],
            maxPromptLength: $style['max_prompt_length'],
            maxNegativeLength: $style['max_negative_length'],
            // Image2Pano is documented as requiring a Model 4 style.
            supportsImageToPanorama: $style['model_version'] === '4',
            previewUrl: $style['preview_url'],
        ), $styles);

        return array_values($imageToPanorama
            ? array_filter($list, fn (PanoramaStyle $style): bool => $style->supportsImageToPanorama)
            : $list);
    }

    public function generate(string $prompt, ?string $negativePrompt, int $styleId, ?string $webhookUrl): PanoramaStatus
    {
        return $this->submit(array_filter([
            'skybox_style_id' => $styleId,
            'prompt' => $prompt,
            'negative_text' => $negativePrompt,
            'webhook_url' => $webhookUrl,
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    public function convertImageToPanorama(string $imageUrl, string $prompt, ?string $negativePrompt, int $styleId, ?string $webhookUrl): PanoramaStatus
    {
        return $this->submit(array_filter([
            'skybox_style_id' => $styleId,
            'prompt' => $prompt,
            'negative_text' => $negativePrompt,
            'image_to_pano_init_image' => $imageUrl,
            'webhook_url' => $webhookUrl,
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    public function getStatus(string $requestId): PanoramaStatus
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/imagine/requests/'.rawurlencode($requestId)));

        return $this->normalise($response->json('request') ?? $response->json() ?? []);
    }

    public function cancel(string $requestId): void
    {
        $this->send(fn (PendingRequest $http) => $http->delete('/imagine/requests/'.rawurlencode($requestId)));
    }

    public function parseWebhook(string $rawBody, array $headers): ?PanoramaStatus
    {
        $secret = (string) config('services.skybox.webhook_secret');
        $signature = (string) ($headers['x-signature'] ?? '');
        $timestamp = (int) ($headers['x-timestamp'] ?? 0);
        $tolerance = (int) config('ai.providers.skybox.webhook_tolerance_seconds', 300);

        if ($secret === '' || $signature === '' || abs(time() - $timestamp) > $tolerance) {
            return null;
        }

        if (! hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
            return null;
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return null;
        }

        return $this->normalise(is_array($payload['request'] ?? null) ? $payload['request'] : $payload);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function submit(array $body): PanoramaStatus
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/skybox', $body));

        return $this->normalise($response->json() ?? []);
    }

    /**
     * @param  array<string, mixed>  $request
     */
    protected function normalise(array $request): PanoramaStatus
    {
        if (! isset($request['id'])) {
            throw new ProviderException('Skybox returned an unexpected response.', retryable: true);
        }

        $state = match ($request['status'] ?? null) {
            'complete' => PanoramaStatus::COMPLETED,
            'processing' => PanoramaStatus::PROCESSING,
            'abort' => PanoramaStatus::CANCELLED,
            'error' => PanoramaStatus::FAILED,
            default => PanoramaStatus::QUEUED,
        };

        return new PanoramaStatus(
            requestId: (string) $request['id'],
            state: $state,
            fileUrl: filled($request['file_url'] ?? null) ? (string) $request['file_url'] : null,
            errorMessage: isset($request['error_message']) ? (string) $request['error_message'] : null,
            providerCredits: isset($request['credit_cost']) ? (int) $request['credit_cost'] : null,
            model: isset($request['model']) ? (string) $request['model'] : null,
            raw: $request,
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    protected function send(callable $call): Response
    {
        if (! $this->isConfigured()) {
            throw ProviderException::notConfigured('Skybox');
        }

        $http = Http::baseUrl(rtrim((string) config('ai.providers.skybox.base_url'), '/'))
            ->withHeaders(['x-api-key' => (string) config('services.skybox.api_key')])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('ai.providers.skybox.timeout', 30));

        try {
            $response = $call($http);
        } catch (ConnectionException $exception) {
            throw new ProviderException('Could not reach Skybox.', retryable: true, previous: $exception);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $detail = (string) ($response->json('error') ?? $response->json('message') ?? 'request failed');

        throw new ProviderException(
            match (true) {
                $status === 403 => 'Skybox denied access. Model 4 and photo-to-360 need a Skybox Standard or Business plan.',
                $status === 429 => 'Skybox is busy with other generations on this account. Retrying shortly.',
                default => "Skybox error ({$status}): {$detail}",
            },
            retryable: $status === 429 || $status >= 500,
            status: $status,
        );
    }
}
