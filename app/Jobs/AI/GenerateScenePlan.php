<?php

namespace App\Jobs\AI;

use App\Enums\GenerationStatus;
use App\Exceptions\ProviderException;
use App\Models\AiGeneration;
use App\Services\AI\GenerationLifecycle;
use App\Services\AI\ScenePlanningService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateScenePlan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public AiGeneration $generation)
    {
        $this->onQueue('ai-analysis');
    }

    public function uniqueId(): string
    {
        return (string) $this->generation->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(ScenePlanningService $planner, GenerationLifecycle $lifecycle): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status->isFinal()) {
            return;
        }

        $lifecycle->advance($generation, GenerationStatus::Processing);

        try {
            ['plan' => $plan, 'result' => $result] = $planner->plan($generation->input_payload ?? []);
        } catch (ProviderException $exception) {
            if ($exception->retryable && $this->attempts() < $this->tries) {
                throw $exception;
            }

            $lifecycle->fail($generation, $exception->getMessage());

            return;
        }

        $generation->refresh();
        $generation->model = $result->model;
        $generation->provider_request_id = $result->providerRequestId;
        $generation->actual_cost = $result->costUsd;
        $generation->mergeOutput([
            'plan' => $plan,
            'usage' => ['input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens],
        ]);
        $generation->save();

        $lifecycle->complete($generation);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationLifecycle::class)->fail($this->generation, $exception?->getMessage() ?? 'Scene planning failed.');
    }
}
