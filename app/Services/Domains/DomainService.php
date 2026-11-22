<?php

namespace App\Services\Domains;

use App\Enums\Entitlement;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDomain;
use App\Services\ActivityLogger;
use App\Services\Billing\EntitlementService;
use App\Services\Cloudflare\SyncCloudflareHostname;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Custom domains: claiming, DNS verification and request lookup.
 *
 * A domain is served only when it is verified (the TXT record proves
 * ownership and the domain points at this app) and the workspace's plan
 * includes custom domains.
 */
class DomainService
{
    public function __construct(
        protected DnsLookup $dns,
        protected EntitlementService $entitlements,
        protected ActivityLogger $activity,
        protected SyncCloudflareHostname $cloudflare,
    ) {}

    public static function normalise(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('#^https?://#', '', $host);

        return rtrim(Str::before(Str::before($host, '/'), ':'), '.');
    }

    /**
     * Hosts that serve the whole application, never a workspace.
     *
     * @return list<string>
     */
    public static function appHosts(): array
    {
        return array_values(array_unique(array_filter([
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            strtolower((string) config('domains.origin_hostname')),
            strtolower((string) config('domains.cname_target')),
            ...array_map('strtolower', (array) config('domains.app_hosts', [])),
        ])));
    }

    public function connect(Workspace $workspace, User $user, string $input): WorkspaceDomain
    {
        $this->entitlements->ensureAllowed($workspace->id, Entitlement::CustomDomain);

        $host = self::normalise($input);
        $this->validate($host, $workspace);

        $domain = $workspace->domain ?? new WorkspaceDomain;

        if ($domain->exists && $domain->host !== $host) {
            // A different name needs its own Cloudflare hostname.
            $this->cloudflare->forget($domain);
            $domain->forceFill($this->clearedCloudflare());
        }

        $domain->fill([
            'host' => $host,
            'status' => 'pending',
            'verification_token' => $domain->host === $host && $domain->verification_token ? $domain->verification_token : WorkspaceDomain::newToken(),
            'verified_at' => null,
            'last_checked_at' => null,
            'last_error' => null,
        ]);
        $domain->workspace_id = $workspace->id;
        $domain->created_by ??= $user->id;
        $domain->save();

        $this->cloudflare->sync($domain);
        $this->forget($domain);
        $this->activity->log($workspace->id, $user, 'workspace.domain_added', "Connected the domain {$host}", $domain);

        return $domain;
    }

    /**
     * Check DNS now. Returns whether the domain is verified.
     */
    public function verify(WorkspaceDomain $domain): bool
    {
        $this->cloudflare->sync($domain);

        // Cloudflare activating the hostname and certificate is proof of control too.
        $error = SyncCloudflareHostname::isLive($domain) ? null : $this->dnsError($domain);

        $domain->fill([
            'status' => $error === null ? 'verified' : ($domain->isVerified() || $domain->status === 'failed' ? 'failed' : 'pending'),
            'verified_at' => $error === null ? ($domain->verified_at ?? now()) : $domain->verified_at,
            'last_checked_at' => now(),
            'last_error' => $error,
        ])->save();

        $this->forget($domain);

        return $error === null;
    }

    public function remove(WorkspaceDomain $domain, User $user): void
    {
        $this->cloudflare->forget($domain);
        $domain->delete();
        $this->forget($domain);
        $this->activity->log($domain->workspace_id, $user, 'workspace.domain_removed', "Removed the domain {$domain->host}");
    }

    /**
     * The verified domain for an incoming request host, if any (cached).
     */
    public function forHost(string $host): ?WorkspaceDomain
    {
        $host = self::normalise($host);

        $id = Cache::remember(self::cacheKey($host), (int) config('domains.cache_seconds', 300), fn (): int => (int) WorkspaceDomain::where('host', $host)->where('status', 'verified')->value('id'));

        return $id ? WorkspaceDomain::with('workspace')->find($id) : null;
    }

    /**
     * Whether the workspace currently gets its custom domain served.
     */
    public function serves(WorkspaceDomain $domain): bool
    {
        return $domain->isVerified() && $this->entitlements->allows($domain->workspace_id, Entitlement::CustomDomain);
    }

    protected function validate(string $host, Workspace $workspace): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['host' => $message]);

        if (strlen($host) > 253 || ! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $host)) {
            $fail(__('Enter a domain name such as tours.youragency.com.'));
        }

        if (filter_var($host, FILTER_VALIDATE_IP) || Str::endsWith($host, ['.localhost', '.local', '.internal', '.test', '.example', '.invalid'])) {
            $fail(__('Use a public domain name you own.'));
        }

        foreach (self::appHosts() as $appHost) {
            if ($host === $appHost || Str::endsWith($host, '.'.$appHost)) {
                $fail(__('This domain belongs to the platform itself.'));
            }
        }

        $taken = WorkspaceDomain::where('host', $host)->where('workspace_id', '!=', $workspace->id)->exists();

        if ($taken) {
            $fail(__('This domain is already connected to another workspace.'));
        }
    }

    /**
     * Why the domain is not ready yet, or null when DNS is correct.
     */
    protected function dnsError(WorkspaceDomain $domain): ?string
    {
        $txt = $this->dns->txt($domain->txtRecordName());

        if (! in_array($domain->verification_token, array_map('trim', $txt), true)) {
            return __('The TXT record :name was not found or has a different value.', ['name' => $domain->txtRecordName()]);
        }

        $target = strtolower((string) config('domains.cname_target'));
        $ip = config('domains.a_record');

        if (in_array($target, $this->dns->cname($domain->host), true)) {
            return null;
        }

        if ($ip && in_array($ip, $this->dns->a($domain->host), true)) {
            return null;
        }

        return $ip
            ? __('Point :host at :target with a CNAME record, or at :ip with an A record.', ['host' => $domain->host, 'target' => $target, 'ip' => $ip])
            : __('Point :host at :target with a CNAME record.', ['host' => $domain->host, 'target' => $target]);
    }

    /**
     * @return array<string, null>
     */
    protected function clearedCloudflare(): array
    {
        return [
            'cloudflare_hostname_id' => null,
            'cloudflare_status' => null,
            'cloudflare_ssl_status' => null,
            'cloudflare_ownership_txt_name' => null,
            'cloudflare_ownership_txt_value' => null,
            'cloudflare_errors' => null,
            'cloudflare_synced_at' => null,
        ];
    }

    protected function forget(WorkspaceDomain $domain): void
    {
        Cache::forget(self::cacheKey($domain->host));
        app(PublicUrl::class)->forget($domain->workspace_id);
    }

    protected static function cacheKey(string $host): string
    {
        return 'domains:host:'.sha1($host);
    }
}
