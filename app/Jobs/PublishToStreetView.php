<?php

namespace App\Jobs;

use App\Exceptions\ProviderException;
use App\Models\StreetViewPublication;
use App\Services\StreetView\StreetViewPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Uploads one scene to Google Street View. Retried a few times when Google
 * is briefly unavailable; every other failure is recorded on the publication.
 */
class PublishToStreetView implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 420;

    public function __construct(public StreetViewPublication $publication) {}

    public function handle(StreetViewPublisher $publisher): void
    {
        try {
            $publisher->run($this->publication);
        } catch (ProviderException $exception) {
            if ($exception->retryable && $this->attempts() < $this->tries) {
                $this->release(30);

                return;
            }

            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->publication->forceFill([
            'status' => StreetViewPublication::FAILED,
            'error' => 'The upload did not finish. Please try again.',
        ])->save();
    }
}
