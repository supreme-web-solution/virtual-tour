<?php

namespace App\Jobs\Image;

use App\Exceptions\ProviderException;
use App\Models\AiGeneration;
use App\Services\AI\GenerationLifecycle;
use App\Services\Image\PhotoEnhancementService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One AI photo edit. Runs on the long-running connection: image edits can
 * take a minute or two.
 */
class EnhancePhoto implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 280;

    public function __construct(public AiGeneration $generation)
    {
        $this->onConnection((string) config('queue.long_connection', 'redis-long'));
        $this->onQueue('enhance');
    }

    public function uniqueId(): string
    {
        return (string) $this->generation->id;
    }

    public function handle(PhotoEnhancementService $enhancements): void
    {
        try {
            $enhancements->run($this->generation);
        } catch (ProviderException $exception) {
            // Retryable provider problems are tried again.
            if ($exception->retryable && $this->attempts() < $this->tries) {
                $this->release(20);

                return;
            }

            // Retries used up: give up and return the credits.
            if ($exception->retryable) {
                app(GenerationLifecycle::class)->fail($this->generation, $exception->getMessage());
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationLifecycle::class)->fail($this->generation, $exception?->getMessage() ?? 'The photo edit failed.');
    }
}
