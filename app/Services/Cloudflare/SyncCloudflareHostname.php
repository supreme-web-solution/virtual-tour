<?php

namespace App\Services\Cloudflare;

use App\Models\WorkspaceDomain;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a workspace domain's Cloudflare custom hostname in step with our row:
 * create it, refresh its status, or delete it.
 *
 * @phpstan-import-type Hostname from CloudflareCustomHostnames
 */
class SyncCloudflareHostname
{
    public function __construct(protected CloudflareCustomHostnames $cloudflare) {}

    public function enabled(): bool
    {
        return $this->cloudflare->configured();
    }

    /**
     * Create the custom hostname when the domain has none, otherwise refresh it.
     */
    public function sync(WorkspaceDomain $domain): WorkspaceDomain
    {
        if (! $this->enabled()) {
            return $domain;
        }

        try {
            $hostname = $domain->cloudflare_hostname_id
                ? $this->cloudflare->get($domain->cloudflare_hostname_id)
                : $this->cloudflare->create($domain->host);

            $this->apply($domain, $hostname);
        } catch (Throwable $exception) {
            // A stale id (deleted in the dashboard): start again next time.
            if ($exception instanceof RequestException && $exception->response->status() === 404) {
                $domain->forceFill(['cloudflare_hostname_id' => null])->save();

                return $this->sync($domain);
            }

            $this->recordFailure($domain, $exception);
        }

        return $domain;
    }

    /**
     * Remove the custom hostname (by stored id, or by name when the id is missing).
     */
    public function forget(WorkspaceDomain $domain): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $id = $domain->cloudflare_hostname_id ?? $this->cloudflare->findByHostname($domain->host)['id'] ?? null;

            if ($id) {
                $this->cloudflare->delete($id);
            }
        } catch (Throwable $exception) {
            Log::warning('Could not delete the Cloudflare custom hostname.', ['host' => $domain->host, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Whether Cloudflare reports the hostname and its certificate as active.
     */
    public static function isLive(WorkspaceDomain $domain): bool
    {
        return $domain->cloudflare_status === 'active' && $domain->cloudflare_ssl_status === 'active';
    }

    /**
     * @param  Hostname  $hostname
     */
    public function apply(WorkspaceDomain $domain, array $hostname): void
    {
        $domain->forceFill([
            'cloudflare_hostname_id' => $hostname['id'] ?: null,
            'cloudflare_status' => $hostname['status'],
            'cloudflare_ssl_status' => $hostname['ssl_status'],
            'cloudflare_ownership_txt_name' => $hostname['ownership_txt_name'],
            'cloudflare_ownership_txt_value' => $hostname['ownership_txt_value'],
            'cloudflare_errors' => $hostname['errors'] ?: null,
            'cloudflare_synced_at' => now(),
        ])->save();
    }

    protected function recordFailure(WorkspaceDomain $domain, Throwable $exception): void
    {
        Log::warning('Cloudflare custom hostname sync failed.', ['host' => $domain->host, 'error' => $exception->getMessage()]);

        $message = $exception instanceof RequestException
            ? (string) ($exception->response->json('errors.0.message') ?? $exception->getMessage())
            : $exception->getMessage();

        $domain->forceFill([
            'cloudflare_errors' => [$message],
            'cloudflare_synced_at' => now(),
        ])->save();
    }
}
