<?php

namespace App\Services\AI;

use App\Contracts\AI\StructuredOutputProvider;
use App\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Responses API with Structured Outputs (strict JSON schema).
 *
 * @see https://developers.openai.com/api/docs/guides/structured-outputs
 */
class OpenAIProvider implements StructuredOutputProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.openai.api_key'));
    }

    public function generate(StructuredRequest $request): StructuredResult
    {
        if (! $this->isConfigured()) {
            throw ProviderException::notConfigured('OpenAI');
        }

        $content = [['type' => 'input_text', 'text' => $request->prompt]];

        foreach ($request->imageUrls as $url) {
            $content[] = ['type' => 'input_image', 'image_url' => $url, 'detail' => 'low'];
        }

        $body = [
            'model' => $request->model,
            'store' => false,
            'max_output_tokens' => $request->maxOutputTokens,
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $request->instructions]]],
                ['role' => 'user', 'content' => $content],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $request->schemaName,
                    'schema' => $request->schema,
                    'strict' => true,
                ],
            ],
        ];

        try {
            $response = Http::withToken((string) config('services.openai.api_key'))
                ->acceptJson()
                ->timeout((int) config('ai.providers.openai.timeout', 90))
                ->post(rtrim((string) config('ai.providers.openai.base_url'), '/').'/responses', $body);
        } catch (ConnectionException $exception) {
            throw new ProviderException('Could not reach OpenAI.', retryable: true, previous: $exception);
        }

        if ($response->failed()) {
            throw $this->httpError($response);
        }

        return $this->parse($response->json() ?? [], $request->model);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    protected function parse(array $json, string $requestedModel): StructuredResult
    {
        $status = $json['status'] ?? null;

        if ($status === 'incomplete') {
            $reason = $json['incomplete_details']['reason'] ?? 'unknown';

            throw new ProviderException("The AI response was incomplete ({$reason}).", retryable: $reason === 'max_output_tokens');
        }

        if ($status !== 'completed') {
            throw new ProviderException('The AI request did not complete (status: '.($status ?? 'unknown').').', retryable: true);
        }

        $text = null;

        foreach ($json['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'refusal') {
                    throw new ProviderException('The AI declined this request: '.($part['refusal'] ?? 'no reason given').'.');
                }

                if (($part['type'] ?? null) === 'output_text') {
                    $text = ($text ?? '').($part['text'] ?? '');
                }
            }
        }

        $data = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($data)) {
            throw new ProviderException('The AI returned a response that was not valid JSON.', retryable: true);
        }

        $model = (string) ($json['model'] ?? $requestedModel);
        $inputTokens = (int) ($json['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($json['usage']['output_tokens'] ?? 0);

        return new StructuredResult(
            data: $data,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costUsd: $this->cost($requestedModel, $inputTokens, $outputTokens),
            providerRequestId: isset($json['id']) ? (string) $json['id'] : null,
        );
    }

    protected function cost(string $model, int $inputTokens, int $outputTokens): ?float
    {
        // Looked up as an array key: model names contain dots ("gpt-6.1-terra"),
        // which dot-notation config paths would split.
        $prices = ((array) config('ai.providers.openai.prices_per_million_tokens'))[$model] ?? null;

        if (! is_array($prices)) {
            return null;
        }

        return round(($inputTokens * $prices['input'] + $outputTokens * $prices['output']) / 1_000_000, 6);
    }

    protected function httpError(Response $response): ProviderException
    {
        $status = $response->status();
        $message = (string) ($response->json('error.message') ?? 'OpenAI request failed.');

        return new ProviderException(
            "OpenAI error ({$status}): {$message}",
            retryable: $status === 429 || $status >= 500,
            status: $status,
        );
    }
}
