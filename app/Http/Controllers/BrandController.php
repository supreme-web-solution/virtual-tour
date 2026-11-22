<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Enums\Entitlement;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDomain;
use App\Services\ActivityLogger;
use App\Services\Billing\EntitlementService;
use App\Services\Cloudflare\SyncCloudflareHostname;
use App\Services\Domains\BrandPresenter;
use App\Services\Domains\DomainService;
use App\Services\Domains\PublicUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace brand (white-label) and custom domain, for owners and admins.
 */
class BrandController extends Controller
{
    public function __construct(
        protected DomainService $domains,
        protected EntitlementService $entitlements,
        protected ActivityLogger $activity,
        protected SyncCloudflareHostname $cloudflare,
    ) {}

    public function index(Request $request): Response
    {
        [$user, $workspace] = $this->context($request);
        $domain = $workspace->domain;

        return Inertia::render('brand/Index', [
            'brand' => $workspace->brand(),
            'workspaceName' => $workspace->name,
            'logos' => MediaAsset::where('workspace_id', $workspace->id)
                ->whereIn('asset_type', [AssetType::Logo->value, AssetType::Photo->value])
                ->latest('id')
                ->limit(60)
                ->get(['ulid', 'original_filename', 'thumbnail_url', 'asset_type'])
                ->map(fn (MediaAsset $asset): array => [
                    'id' => $asset->ulid,
                    'name' => $asset->original_filename,
                    'thumbnail_url' => $asset->thumbnail_url,
                    'is_logo' => $asset->asset_type === AssetType::Logo,
                ])->sortByDesc('is_logo')->values(),
            'domain' => $domain === null ? null : [
                'host' => $domain->host,
                'status' => $domain->status,
                'txt_name' => $domain->txtRecordName(),
                'txt_value' => $domain->verification_token,
                'last_error' => $domain->last_error,
                'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
                'verified_at' => $domain->verified_at?->toIso8601String(),
                'is_serving' => $this->domains->serves($domain),
                'url' => $domain->url(),
                'cloudflare' => $this->cloudflare->enabled() ? [
                    'status' => $domain->cloudflare_status,
                    'ssl_status' => $domain->cloudflare_ssl_status,
                    'https_live' => SyncCloudflareHostname::isLive($domain),
                    'ownership_txt_name' => $domain->cloudflare_ownership_txt_name,
                    'ownership_txt_value' => $domain->cloudflare_ownership_txt_value,
                    'errors' => $domain->cloudflare_errors ?? [],
                ] : null,
            ],
            'dns' => [
                'cname_target' => config('domains.cname_target'),
                'a_record' => config('domains.a_record'),
            ],
            'features' => [
                'white_label' => $this->entitlements->allows($workspace->id, Entitlement::RemoveBranding),
                'custom_domain' => $this->entitlements->allows($workspace->id, Entitlement::CustomDomain),
                'tracking' => $this->entitlements->allows($workspace->id, Entitlement::TrackingScripts),
            ],
            'channel' => [
                'enabled' => (bool) $workspace->channel_enabled,
                'slug' => $workspace->channel_slug,
                'url' => $workspace->channel_slug ? route('channel.show', $workspace->channel_slug) : null,
            ],
            'publicBase' => app(PublicUrl::class)->customOrigin($workspace->id) ?? rtrim((string) config('app.url'), '/'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        [$user, $workspace] = $this->context($request);

        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:80'],
            'logo_asset_id' => ['nullable', 'string', Rule::exists(MediaAsset::class, 'ulid')
                ->where('workspace_id', $workspace->id)
                ->where('cloudinary_resource_type', 'image')],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'ga4_id' => ['nullable', 'string', 'regex:/^G-[A-Za-z0-9]{4,12}$/i'],
            'gtm_id' => ['nullable', 'string', 'regex:/^GTM-[A-Za-z0-9]{4,10}$/i'],
        ], [
            'ga4_id.regex' => __('A GA4 measurement ID looks like G-XXXXXXXXXX.'),
            'gtm_id.regex' => __('A Tag Manager ID looks like GTM-XXXXXXX.'),
        ]);

        // Tracking IDs belong to a plan feature: without it they are not kept.
        if (! $this->entitlements->allows($workspace->id, Entitlement::TrackingScripts)) {
            $data['ga4_id'] = $data['gtm_id'] = null;
        }

        foreach (['ga4_id', 'gtm_id'] as $key) {
            $data[$key] = isset($data[$key]) ? strtoupper($data[$key]) : null;
        }

        $workspace->forceFill(['branding' => array_map(fn ($value) => $value === '' ? null : $value, $data)])->save();
        app(BrandPresenter::class)->forget($workspace->id);
        $this->activity->log($workspace->id, $user, 'workspace.brand_updated', 'Updated the workspace brand');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand saved.')]);

        return back();
    }

    /**
     * Turn the public channel page on or off and choose its address.
     */
    public function updateChannel(Request $request): RedirectResponse
    {
        [$user, $workspace] = $this->context($request);

        $data = $request->validate([
            'channel_enabled' => ['required', 'boolean'],
            'channel_slug' => [
                'nullable', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('workspaces', 'channel_slug')->ignore($workspace->id),
                Rule::requiredIf($request->boolean('channel_enabled')),
            ],
        ], ['channel_slug.required' => __('Choose the address of your channel page.')]);

        $workspace->forceFill([
            'channel_enabled' => (bool) $data['channel_enabled'],
            'channel_slug' => $data['channel_slug'] ?: null,
        ])->save();
        $this->activity->log($workspace->id, $user, 'workspace.channel_updated', $workspace->channel_enabled ? 'Published the channel page' : 'Hid the channel page');

        Inertia::flash('toast', ['type' => 'success', 'message' => $workspace->channel_enabled ? __('Your channel page is live.') : __('Your channel page is hidden.')]);

        return back();
    }

    public function storeDomain(Request $request): RedirectResponse
    {
        [$user, $workspace] = $this->context($request);
        $data = $request->validate(['host' => ['required', 'string', 'max:253']]);

        $domain = $this->domains->connect($workspace, $user, $data['host']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Add the DNS records below for :host, then check it.', ['host' => $domain->host])]);

        return back();
    }

    public function verifyDomain(Request $request): RedirectResponse
    {
        [, $workspace] = $this->context($request);
        $domain = $workspace->domain ?? abort(404);

        $verified = $this->domains->verify($domain);

        Inertia::flash('toast', $verified
            ? ['type' => 'success', 'message' => __(':host is connected. Share links now use it.', ['host' => $domain->host])]
            : ['type' => 'error', 'message' => __('Not ready yet: :reason DNS changes can take up to an hour to appear.', ['reason' => $domain->last_error])]);

        return back();
    }

    public function destroyDomain(Request $request): RedirectResponse
    {
        [$user, $workspace] = $this->context($request);
        $domain = $workspace->domain ?? abort(404);

        $this->domains->remove($domain, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Domain removed. Share links use the platform address again.')]);

        return back();
    }

    /**
     * For the web server's on-demand TLS: may a certificate be issued for
     * this host? Only for verified, served custom domains.
     */
    public function tlsCheck(Request $request): HttpResponse
    {
        $host = DomainService::normalise($request->string('domain')->toString());
        $domain = WorkspaceDomain::where('host', $host)->first();

        abort_unless($domain !== null && $this->domains->serves($domain), 404);

        return response('ok');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    protected function context(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::with('domain')->findOrFail($user->current_workspace_id);
        abort_unless((bool) $user->roleIn($workspace)?->canManage(), 403);

        return [$user, $workspace];
    }
}
