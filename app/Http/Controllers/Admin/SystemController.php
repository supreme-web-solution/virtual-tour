<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Video\VideoRenderer;
use App\Http\Controllers\Controller;
use App\Support\ProviderConfiguration;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuration status. Only whether something is configured is shown —
 * never a key, secret or connection string.
 */
class SystemController extends Controller
{
    public function __invoke(ProviderConfiguration $providers, VideoRenderer $renderer, Schedule $schedule, ConsoleKernel $console): Response
    {
        // routes/console.php (where tasks are scheduled) only loads with the console kernel.
        $console->all();

        return Inertia::render('admin/System', [
            'app' => [
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'url' => config('app.url'),
                'php' => PHP_VERSION,
                'laravel' => Application::VERSION,
                'timezone' => config('app.timezone'),
            ],
            'infrastructure' => [
                'database' => config('database.default'),
                'cache' => config('cache.default'),
                'queue' => config('queue.default'),
                'long_queue' => config('queue.long_connection'),
                'session' => config('session.driver'),
                'mail' => config('mail.default'),
                'domains_cname' => config('domains.cname_target'),
                'domains_a' => config('domains.a_record'),
            ],
            'providers' => [
                ['name' => 'Cloudinary (media storage)', 'configured' => $providers->features()['media_uploads']],
                ['name' => 'OpenAI (analysis and planning)', 'configured' => $providers->features()['ai_analysis']],
                ['name' => 'Skybox (360° generation)', 'configured' => $providers->features()['panorama_generation']],
                ['name' => 'fal.ai (video and music)', 'configured' => $providers->features()['video_generation']],
                ['name' => 'FFmpeg (video rendering)', 'configured' => $renderer->isAvailable()],
            ],
            'schedule' => array_values(array_map(fn (Event $event): array => [
                'command' => trim(preg_replace('/^.*artisan["\']?\s+/', '', (string) $event->command) ?? ''),
                'expression' => $event->expression,
                'description' => $event->description,
            ], $schedule->events())),
        ]);
    }
}
