<?php

namespace App\Services\AI;

use App\Enums\GenerationStatus;
use App\Jobs\Tours\ContinueTourBuild;
use App\Models\AiGeneration;
use App\Services\ActivityLogger;
use App\Services\Credits\CreditService;
use Closure;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The only place generation statuses change, so credits always follow:
 * completed → charge, failed/cancelled → release. Final states never move.
 */
class GenerationLifecycle
{
    public function __construct(
        protected CreditService $credits,
        protected ActivityLogger $activity,
    ) {}

    /**
     * Queue the job for generations that already hold credits. If the queue
     * cannot take it (for example Redis is down) nothing would ever run it, so
     * the generations fail on the spot and the credits go back.
     *
     * @param  AiGeneration|list<AiGeneration>  $generations
     * @param  Closure(): void  $dispatch
     *
     * @throws ValidationException
     */
    public function dispatchOrFail(AiGeneration|array $generations, Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $exception) {
            report($exception);

            foreach (is_array($generations) ? $generations : [$generations] as $generation) {
                $this->fail($generation, 'The job queue is unavailable.');
            }

            throw ValidationException::withMessages(['mode' => __('This could not be started right now. Your credits were not used. Please try again in a moment.')]);
        }
    }

    /**
     * Fail generations that were queued long ago and never started (a lost
     * job), returning their credits. Returns how many were released.
     */
    public function releaseStuck(int $minutes = 60): int
    {
        $released = 0;

        AiGeneration::query()
            ->where('status', GenerationStatus::Queued)
            ->whereNull('provider_request_id')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->each(function (AiGeneration $generation) use (&$released): void {
                $this->fail($generation, 'This never started, so it was cancelled and your credits were returned.');
                $released++;
            });

        return $released;
    }

    /**
     * Move an in-flight generation to another in-flight status.
     * Returns false (and changes nothing) when the generation already finished.
     */
    public function advance(AiGeneration $generation, GenerationStatus $status): bool
    {
        $generation->refresh();

        if ($generation->status->isFinal() || $status->isFinal()) {
            return false;
        }

        $generation->status = $status;
        $generation->started_at ??= now();
        $generation->save();

        return true;
    }

    /**
     * Mark complete and charge credits. Call only once the output is stored.
     */
    public function complete(AiGeneration $generation): void
    {
        $generation->refresh();

        if ($generation->status->isFinal()) {
            return;
        }

        $generation->forceFill([
            'status' => GenerationStatus::Completed,
            'completed_at' => now(),
            'error_message' => null,
        ])->save();

        $this->credits->charge($generation);
        $this->continueTourBuild($generation);
    }

    /**
     * Mark failed and return the held credits. Customers are never charged
     * for failed generations; provider-side billing is recorded for reconciliation.
     */
    public function fail(AiGeneration $generation, string $message): void
    {
        $generation->refresh();

        if ($generation->status->isFinal()) {
            return;
        }

        $generation->forceFill([
            'status' => GenerationStatus::Failed,
            'completed_at' => now(),
            'error_message' => mb_substr($message, 0, 2000),
        ])->save();

        $this->credits->release($generation, 'generation failed');

        $this->activity->log(
            $generation->workspace_id,
            $generation->user,
            'generation.failed',
            "{$generation->generation_type->label()} failed: ".mb_substr($message, 0, 150),
            $generation,
        );

        $this->continueTourBuild($generation);
    }

    /**
     * A plan or photo analysis started by one-click tour creation moves the
     * build on to its next step (or reports the failure) once it finishes.
     */
    protected function continueTourBuild(AiGeneration $generation): void
    {
        if ($generation->input('auto_tour_id') !== null) {
            ContinueTourBuild::dispatch($generation)->afterCommit();
        }
    }

    public function cancel(AiGeneration $generation): void
    {
        $generation->refresh();

        if ($generation->status->isFinal()) {
            return;
        }

        $generation->forceFill([
            'status' => GenerationStatus::Cancelled,
            'completed_at' => now(),
        ])->save();

        $this->credits->release($generation, 'cancelled');
    }
}
