<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Failed queue jobs (the `failed_jobs` table Horizon also writes to).
 */
class FailedJobController extends Controller
{
    public function index(): Response
    {
        $jobs = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->paginate(25)
            ->through(function (stdClass $job): array {
                $payload = json_decode((string) $job->payload, true) ?: [];

                return [
                    'uuid' => $job->uuid,
                    'job' => class_basename((string) ($payload['displayName'] ?? 'Unknown job')),
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'attempts' => (int) ($payload['attempts'] ?? 0),
                    // Only the exception's first line: stack traces can contain request data.
                    'exception' => Str::limit(strtok((string) $job->exception, "\n") ?: '', 300),
                    'failed_at' => $job->failed_at,
                ];
            });

        return Inertia::render('admin/FailedJobs', ['jobs' => $jobs]);
    }

    public function retry(string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('The job was pushed back onto its queue.')]);

        return back();
    }

    public function destroy(string $uuid): RedirectResponse
    {
        DB::table('failed_jobs')->where('uuid', $uuid)->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Failed job removed.')]);

        return back();
    }

    public function flush(): RedirectResponse
    {
        DB::table('failed_jobs')->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('All failed jobs were removed.')]);

        return back();
    }
}
