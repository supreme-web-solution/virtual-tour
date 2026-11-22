<?php

namespace App\Jobs\Import;

use App\Models\PropertyImport;
use App\Services\Import\PropertyImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Read a listing page: robots.txt check, fetch, parse and (optionally) AI
 * extraction. Runs on the long-running connection: two slow websites plus
 * an AI call can take longer than the default workers allow.
 */
class FetchListing implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public PropertyImport $import)
    {
        $this->onConnection((string) config('queue.long_connection', 'redis-long'));
        $this->onQueue('imports');
    }

    public function uniqueId(): string
    {
        return (string) $this->import->id;
    }

    public function handle(PropertyImportService $imports): void
    {
        $imports->fetch($this->import);
    }

    public function failed(?Throwable $exception): void
    {
        app(PropertyImportService::class)->failImport($this->import, __('Something went wrong while reading the page. Please try again.'));
    }
}
