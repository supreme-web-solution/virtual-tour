<?php

use App\Enums\GenerationStatus;
use App\Jobs\Panorama\PollPanoramaGeneration;
use App\Models\AiGeneration;
use App\Models\User;
use App\Models\WorkspaceDomain;
use App\Services\AI\GenerationLifecycle;
use App\Services\Billing\SubscriptionService;
use App\Services\Cloudflare\SyncCloudflareHostname;
use App\Services\Credits\CreditService;
use App\Services\Domains\DomainService;
use App\Services\Tours\TourAnalyticsService;
use App\Services\Video\VideoAssemblyService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('credits:grant {email : Owner email of the workspace} {amount : Credits to add} {--reason=Manual grant}', function (CreditService $credits) {
    $user = User::where('email', $this->argument('email'))->first();
    $amount = (int) $this->argument('amount');

    if (! $user?->current_workspace_id || $amount <= 0) {
        $this->error('Unknown user (or user without a workspace), or amount is not positive.');

        return 1;
    }

    $reason = $this->option('reason');
    $credits->grant($user->current_workspace_id, $amount, is_string($reason) ? $reason : 'Manual grant');
    $this->info("Granted {$amount} credits. Available now: ".$credits->available($user->current_workspace_id));

    return 0;
})->purpose("Add AI credits to a user's current workspace");

/*
 * Safety net for panorama generations whose poll chain was lost (worker
 * restart, deploy). Re-queues a status check for anything submitted but
 * silent for 10 minutes.
 */
Artisan::command('generations:recover', function () {
    $stale = AiGeneration::whereIn('status', [GenerationStatus::Submitted->value, GenerationStatus::Processing->value])
        ->whereNotNull('provider_request_id')
        ->where('updated_at', '<', now()->subMinutes(10))
        ->limit(100)
        ->get();

    $stale->each(function (AiGeneration $generation): void {
        $generation->touch();
        PollPanoramaGeneration::dispatch($generation);
    });

    $this->info("Re-queued {$stale->count()} generation(s).");
})->purpose('Re-check panorama generations that have gone quiet');

Schedule::command('generations:recover')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('analytics:prune', function (TourAnalyticsService $analytics) {
    $days = (int) config('analytics.retention_days', 395);
    $this->info('Deleted '.$analytics->prune($days)." visit(s) older than {$days} days.");
})->purpose('Delete tour analytics older than the retention period');

Schedule::command('analytics:prune')->dailyAt('03:30');

Artisan::command('videos:clean-renders', function (VideoAssemblyService $assembly) {
    $hours = (int) config('ai.video.temp_retention_hours', 24);
    $this->info('Removed '.$assembly->cleanOldRenders($hours)." render folder(s) older than {$hours} hours.");
})->purpose('Delete leftover video render folders');

Schedule::command('videos:clean-renders')->hourly();

/*
 * Roll subscriptions into their next billing period (granting that period's
 * plan credits once) and put plan-less workspaces on the default plan.
 */
Artisan::command('subscriptions:renew', function (SubscriptionService $subscriptions) {
    $result = $subscriptions->renewDue();
    $this->info("Renewed {$result['renewed']} subscription(s); started {$result['started']} default plan(s).");
})->purpose('Renew due subscriptions and grant monthly plan credits');

Schedule::command('subscriptions:renew')->hourly()->withoutOverlapping();

Artisan::command('admin:grant {email : The user to make a platform administrator} {--revoke : Remove admin access instead}', function () {
    $user = User::where('email', $this->argument('email'))->first();

    if ($user === null) {
        $this->error('No user with that email.');

        return 1;
    }

    $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
    $this->info($user->is_admin ? "{$user->email} is now a platform administrator." : "{$user->email} is no longer an administrator.");

    return 0;
})->purpose('Grant or revoke admin console access');

/*
 * Re-check custom domains: pending ones become verified once DNS is in
 * place; verified ones whose records were removed stop being served.
 */
Artisan::command('domains:verify', function (DomainService $domains) {
    $checked = 0;

    WorkspaceDomain::whereIn('status', ['pending', 'verified', 'failed'])
        ->where(fn ($query) => $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subMinutes(30)))
        ->orderBy('last_checked_at')
        ->limit(200)
        ->get()
        ->each(function (WorkspaceDomain $domain) use ($domains, &$checked): void {
            $domains->verify($domain);
            $checked++;
        });

    $this->info("Checked {$checked} domain(s).");
})->purpose('Verify custom domain DNS records');

Schedule::command('domains:verify')->everyThirtyMinutes()->withoutOverlapping();

/*
 * Refresh Cloudflare custom hostnames that are not fully active yet, so the
 * Brand page moves from "setting up HTTPS" to live without a manual check.
 */
Artisan::command('domains:refresh-cloudflare', function (SyncCloudflareHostname $cloudflare) {
    if (! $cloudflare->enabled()) {
        return 0;
    }

    $refreshed = 0;

    WorkspaceDomain::query()
        ->where(fn ($query) => $query->whereNull('cloudflare_status')->orWhere('cloudflare_status', '!=', 'active')->orWhere('cloudflare_ssl_status', '!=', 'active')->orWhereNull('cloudflare_ssl_status'))
        ->orderBy('cloudflare_synced_at')
        ->limit(50)
        ->get()
        ->each(function (WorkspaceDomain $domain) use ($cloudflare, &$refreshed): void {
            $cloudflare->sync($domain);
            $refreshed++;
        });

    $this->info("Refreshed {$refreshed} Cloudflare hostname(s).");

    return 0;
})->purpose('Refresh pending Cloudflare custom hostnames');

Schedule::command('domains:refresh-cloudflare')->everyMinute()->withoutOverlapping();

/*
 * Safety net for credits: an AI job that was queued but never started (for
 * example the queue was reset) is cancelled after an hour and its credits go back.
 */
Artisan::command('generations:release-stuck {--minutes=60 : How long a queued job may wait}', function (GenerationLifecycle $lifecycle) {
    $this->info('Released '.$lifecycle->releaseStuck((int) $this->option('minutes')).' stuck generation(s).');
})->purpose('Cancel AI jobs that never started and return their credits');

Schedule::command('generations:release-stuck')->everyTenMinutes()->withoutOverlapping();
