<?php

namespace App\Services\Image;

use App\Contracts\Image\ImageEnhancementProvider;
use App\Exceptions\ProviderException;
use App\Support\TemporaryFiles;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Photo editing through OpenAI's image edit endpoint (multipart upload).
 * Generative editing redraws the photo, so prompts must forbid changing
 * what the property looks like (see PhotoEnhancementService).
 *
 * @see https://developers.openai.com/api/reference/resources/images/methods/edit
 */
class OpenAIImageProvider implements ImageEnhancementProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return (string) config('ai.providers.openai.image_model');
    }

    public function isConfigured(): bool
    {
        return filled(config('services.openai.api_key'));
    }

    public function enhance(ImageEditRequest $request): ImageEditResult
    {
        if (! $this->isConfigured()) {
            throw ProviderException::notConfigured('OpenAI');
        }

        $source = fopen($request->imagePath, 'r');

        if ($source === false) {
            throw new ProviderException('The source photo could not be read.');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('ai.providers.openai.base_url'), '/'))
                ->withToken((string) config('services.openai.api_key'))
                ->acceptJson()
                ->timeout((int) config('ai.providers.openai.image_timeout', 240))
                ->attach('image[]', $source, basename($request->imagePath))
                ->post('/images/edits', [
                    'model' => $this->model(),
                    'prompt' => $request->prompt,
                    'size' => $request->size,
                    'quality' => $request->quality,
                    'output_format' => 'jpeg',
                    'output_compression' => 92,
                    'n' => 1,
                ]);
        } catch (ConnectionException $exception) {
            throw new ProviderException('Could not reach OpenAI (the edit may have timed out).', retryable: true, previous: $exception);
        } finally {
            fclose($source);
        }

        if ($response->failed()) {
            $status = $response->status();
            $message = (string) ($response->json('error.message') ?? 'The image edit failed.');

            throw new ProviderException(
                $status === 400 && str_contains(strtolower($message), 'safety') ? 'OpenAI declined to edit this photo (safety system).' : "OpenAI image error ({$status}): {$message}",
                retryable: $status === 429 || $status >= 500,
                status: $status,
            );
        }

        $encoded = $response->json('data.0.b64_json');
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;

        if ($bytes === false || strlen($bytes) < 1024) {
            throw new ProviderException('OpenAI did not return an image.', retryable: true);
        }

        $path = TemporaryFiles::path('jpg');
        file_put_contents($path, $bytes);
        $size = @getimagesize($path);

        if ($size === false) {
            TemporaryFiles::delete($path);

            throw new ProviderException('OpenAI returned an unreadable image.', retryable: true);
        }

        $input = (int) $response->json('usage.input_tokens', 0);
        $output = (int) $response->json('usage.output_tokens', 0);

        return new ImageEditResult(
            path: $path,
            mimeType: 'image/jpeg',
            width: $size[0],
            height: $size[1],
            costUsd: $this->cost($input, $output),
            inputTokens: $input,
            outputTokens: $output,
        );
    }

    protected function cost(int $input, int $output): ?float
    {
        $prices = ((array) config('ai.providers.openai.image_prices_per_million_tokens'))[$this->model()] ?? null;

        return is_array($prices)
            ? round(($input * $prices['input'] + $output * $prices['output']) / 1_000_000, 6)
            : null;
    }
}
