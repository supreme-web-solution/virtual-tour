<?php

namespace App\Services\Fal;

use App\Exceptions\ProviderException;
use App\Support\TemporaryFiles;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * fal.ai queue API: submit a request, wait for it to finish, return the
 * model's result JSON. Status and result URLs are always taken from fal's
 * own response (building them by hand breaks for multi-part model ids).
 *
 * @see https://fal.ai/docs/model-apis/model-endpoints/queue
 */
class FalClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.fal.key'));
    }

    /**
     * Run a model and wait for its result.
     *
     * @param  array<string, mixed>  $input
     * @return array{request_id: string, result: array<string, mixed>}
     */
    public function run(string $model, array $input, int $timeoutSeconds): array
    {
        if (! $this->isConfigured()) {
            throw ProviderException::notConfigured('fal.ai');
        }

        $submitted = $this->send(fn (PendingRequest $http): Response => $http->post(rtrim((string) config('ai.providers.fal.queue_url'), '/').'/'.ltrim($model, '/'), $input));

        $requestId = (string) $submitted->json('request_id');
        $statusUrl = (string) $submitted->json('status_url');
        $responseUrl = (string) $submitted->json('response_url');

        if ($requestId === '' || ! $this->isQueueUrl($statusUrl) || ! $this->isQueueUrl($responseUrl)) {
            throw new ProviderException('fal.ai did not accept the request.', retryable: true);
        }

        $deadline = time() + $timeoutSeconds;
        $poll = max(1, (int) config('ai.providers.fal.poll_seconds', 5));

        while (true) {
            $status = $this->send(fn (PendingRequest $http): Response => $http->get($statusUrl));
            $state = (string) $status->json('status');

            if ($state === 'COMPLETED') {
                break;
            }

            if (time() + $poll > $deadline) {
                throw new ProviderException('fal.ai took too long to finish this request.', retryable: true);
            }

            sleep($poll);
        }

        // A failed or cancelled request also ends as COMPLETED, with an error field.
        if (filled($status->json('error'))) {
            throw new ProviderException('fal.ai could not generate this: '.$status->json('error'));
        }

        $result = $this->send(fn (PendingRequest $http): Response => $http->get($responseUrl), allowClientErrors: true);

        if ($result->failed() || filled($result->json('error')) || filled($result->json('detail'))) {
            $detail = $result->json('error') ?? $result->json('detail');
            $message = is_string($detail) ? $detail : (is_array($detail) ? (string) ($detail[0]['msg'] ?? json_encode($detail)) : 'request failed');

            throw new ProviderException("fal.ai could not generate this: {$message}", retryable: $result->status() >= 500);
        }

        return ['request_id' => $requestId, 'result' => (array) $result->json()];
    }

    /**
     * Download a generated file (an HTTPS URL from fal's result) to a temp file.
     */
    public function download(string $url, string $extension, int $maxBytes = 500 * 1024 * 1024): string
    {
        if (! str_starts_with($url, 'https://')) {
            throw new ProviderException('fal.ai returned an unexpected file location.');
        }

        $path = TemporaryFiles::path($extension);

        try {
            $response = Http::timeout(180)->sink($path)->get($url);
        } catch (ConnectionException $exception) {
            TemporaryFiles::delete($path);

            throw new ProviderException('The generated file could not be downloaded.', retryable: true, previous: $exception);
        }

        $size = is_file($path) ? (int) filesize($path) : 0;

        if ($response->failed() || $size < 1024 || $size > $maxBytes) {
            TemporaryFiles::delete($path);

            throw new ProviderException('The generated file could not be downloaded.', retryable: true);
        }

        return $path;
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    protected function send(callable $request, bool $allowClientErrors = false): Response
    {
        try {
            $response = $request(Http::withHeaders(['Authorization' => 'Key '.config('services.fal.key')])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(60));
        } catch (ConnectionException $exception) {
            throw new ProviderException('Could not reach fal.ai.', retryable: true, previous: $exception);
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new ProviderException('fal.ai rejected the API key. Check FAL_KEY and that your fal.ai account has credit.', status: $status);
        }

        if ($status === 429 || $status >= 500) {
            throw new ProviderException("fal.ai is busy right now ({$status}).", retryable: true, status: $status);
        }

        if ($response->failed() && ! $allowClientErrors) {
            $detail = $response->json('detail');
            $message = is_string($detail) ? $detail : (is_array($detail) ? (string) ($detail[0]['msg'] ?? 'invalid request') : 'request failed');

            throw new ProviderException("fal.ai error ({$status}): {$message}", status: $status);
        }

        return $response;
    }

    protected function isQueueUrl(string $url): bool
    {
        return str_starts_with($url, rtrim((string) config('ai.providers.fal.queue_url'), '/').'/');
    }
}
