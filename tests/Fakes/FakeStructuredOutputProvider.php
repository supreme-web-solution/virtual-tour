<?php

namespace Tests\Fakes;

use App\Contracts\AI\StructuredOutputProvider;
use App\Exceptions\ProviderException;
use App\Services\AI\StructuredRequest;
use App\Services\AI\StructuredResult;

class FakeStructuredOutputProvider implements StructuredOutputProvider
{
    /** @var list<StructuredRequest> */
    public array $requests = [];

    /**
     * @param  array<string, mixed>|ProviderException  $response
     */
    public function __construct(public array|ProviderException $response = [], public bool $configured = true) {}

    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function generate(StructuredRequest $request): StructuredResult
    {
        $this->requests[] = $request;

        if ($this->response instanceof ProviderException) {
            throw $this->response;
        }

        return new StructuredResult($this->response, $request->model, 1000, 500, 0.0004, 'resp_fake');
    }
}
