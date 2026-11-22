<?php

namespace App\Jobs\Video;

use App\Models\VideoExport;
use App\Services\Video\VideoAssemblyService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs FFmpeg for one export on the single-process rendering queue.
 * Never run inside an HTTP request.
 */
class AssembleVideo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1600;

    public function __construct(public VideoExport $export)
    {
        $this->onConnection((string) config('queue.long_connection', 'redis-long'));
        $this->onQueue('video-rendering');
    }

    public function uniqueId(): string
    {
        return (string) $this->export->id;
    }

    public function handle(VideoAssemblyService $assembly): void
    {
        $assembly->assemble($this->export);
    }

    public function failed(?Throwable $exception): void
    {
        $export = $this->export->refresh();

        if ($export->status !== 'failed') {
            app(VideoAssemblyService::class)->fail($export->project, $export, $exception?->getMessage() ?? 'Rendering failed.');
        }
    }
}
