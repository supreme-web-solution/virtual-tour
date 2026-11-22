<?php

namespace App\Jobs\Import;

use App\Models\PropertyImport;
use App\Services\Import\PropertyImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Copy the selected listing photos into the media library, a chunk at a
 * time; each chunk queues the next so no single job runs for long.
 */
class ImportListingImages implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 10;

    public int $tries = 1;

    public int $timeout = 500;

    public function __construct(public PropertyImport $import, public int $offset = 0)
    {
        $this->onConnection((string) config('queue.long_connection', 'redis-long'));
        $this->onQueue('imports');
    }

    public function handle(PropertyImportService $imports): void
    {
        if ($imports->importImages($this->import, $this->offset, self::CHUNK)) {
            self::dispatch($this->import, $this->offset + self::CHUNK);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->import->refresh()->update([
            'status' => 'completed',
            'error' => trim(($this->import->error ?? '')."\n".__('Copying the photos stopped unexpectedly. Photos copied so far are in the media library.')),
        ]);
    }
}
