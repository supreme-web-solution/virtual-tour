<?php

namespace App\Jobs\Media;

use App\Contracts\Media\MediaStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a file from permanent storage after its library record is gone.
 */
class DeleteStoredMedia implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    public function __construct(
        public string $publicId,
        public string $resourceType,
    ) {
        $this->onQueue('media');
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(MediaStorage $storage): void
    {
        $storage->delete($this->publicId, $this->resourceType);
    }
}
