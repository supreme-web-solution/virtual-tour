<?php

use App\Contracts\Media\MediaStorage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkspaceDomain;
use App\Services\Billing\EntitlementService;
use App\Services\Domains\DnsLookup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeDnsLookup;
use Tests\Fakes\FakeMediaStorage;

const CF_HOSTNAME_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
const CF_ORPHAN_ID = '11111111-2222-3333-4444-555555555555';

beforeEach(function () {
    config([
        'app.url' => 'https://app.platform.com',
        'domains.cname_target' => 'sites.platform.com',
        'domains.app_hosts' => ['localhost'],
        'services.cloudflare.api_token' => 'token',
        'services.cloudflare.zone_id' => 'zone123',
        'services.cloudflare.origin_hostname' => 'origin.platform.com',
        'services.cloudflare.custom_origin' => false,
    ]);

    $this->dns = new FakeDnsLookup;
    $this->app->instance(DnsLookup::class, $this->dns);
    $this->app->instance(MediaStorage::class, new FakeMediaStorage);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    Subscription::query()->where('workspace_id', $this->workspace->id)->delete();
    Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'plan_id' => Plan::factory()->with(['custom_domain' => true])->create()->id,
    ]);
    app(EntitlementService::class)->forget();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cloudflareResult(array $overrides = []): array
{
    return [
        'success' => true,
        'result' => [
            'id' => CF_HOSTNAME_ID,
            'hostname' => 'tours.agency.com',
            'status' => 'pending',
            'ssl' => ['status' => 'pending_validation'],
            'ownership_verification' => ['name' => '_cf-custom-hostname.tours.agency.com', 'value' => 'abc-123'],
            ...$overrides,
        ],
    ];
}

test('connecting a domain creates a Cloudflare custom hostname without the paid origin fields', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(cloudflareResult())]);

    $this->actingAs($this->owner)->post(route('brand.domain.store'), ['host' => 'tours.agency.com'])->assertSessionHasNoErrors();

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/zones/zone123/custom_hostnames')
            && $body['hostname'] === 'tours.agency.com'
            && $body['ssl']['method'] === 'http'
            && ! array_key_exists('custom_origin_server', $body)
            && ! array_key_exists('custom_origin_sni', $body);
    });

    $domain = WorkspaceDomain::sole();
    expect($domain->cloudflare_hostname_id)->toBe(CF_HOSTNAME_ID)
        ->and($domain->cloudflare_ssl_status)->toBe('pending_validation')
        ->and($domain->cloudflare_ownership_txt_name)->toBe('_cf-custom-hostname.tours.agency.com');

    $this->get(route('brand.index'))->assertInertia(fn (Assert $page) => $page
        ->where('domain.cloudflare.ownership_txt_value', 'abc-123')
        ->where('domain.cloudflare.https_live', false));
});

test('the paid origin fields are sent only when custom origin is switched on', function () {
    config(['services.cloudflare.custom_origin' => true]);
    Http::fake(['api.cloudflare.com/*' => Http::response(cloudflareResult())]);

    $this->actingAs($this->owner)->post(route('brand.domain.store'), ['host' => 'tours.agency.com']);

    Http::assertSent(fn (Request $request): bool => ($request->data()['custom_origin_server'] ?? null) === 'origin.platform.com');
});

test('Cloudflare activating the hostname and certificate verifies the domain without DNS records', function () {
    Http::fake(['api.cloudflare.com/*' => Http::sequence()
        ->push(cloudflareResult())
        ->push(cloudflareResult(['status' => 'active', 'ssl' => ['status' => 'active']]))]);

    $this->actingAs($this->owner)->post(route('brand.domain.store'), ['host' => 'tours.agency.com']);
    $this->post(route('brand.domain.verify'))->assertSessionHasNoErrors();

    $domain = WorkspaceDomain::sole();
    expect($domain->status)->toBe('verified')->and($domain->cloudflare_status)->toBe('active');
});

test('a 409 on create reuses the existing hostname and removing the domain deletes it', function () {
    Http::fake([
        'api.cloudflare.com/*custom_hostnames?hostname=*' => Http::response(['result' => [cloudflareResult()['result']]]),
        'api.cloudflare.com/*custom_hostnames/*' => Http::response(['success' => true]),
        'api.cloudflare.com/*custom_hostnames' => Http::response(['success' => false, 'errors' => [['message' => 'duplicate']]], 409),
    ]);

    $this->actingAs($this->owner)->post(route('brand.domain.store'), ['host' => 'tours.agency.com'])->assertSessionHasNoErrors();
    expect(WorkspaceDomain::sole()->cloudflare_hostname_id)->toBe(CF_HOSTNAME_ID);

    $this->delete(route('brand.domain.destroy'));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/custom_hostnames/'.CF_HOSTNAME_ID));
    expect(WorkspaceDomain::count())->toBe(0);
});

test('a Cloudflare failure is recorded for the owner instead of breaking the page', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 1456, 'message' => 'custom_origin_sni is not allowed']]], 400)]);

    $this->actingAs($this->owner)->post(route('brand.domain.store'), ['host' => 'tours.agency.com'])->assertSessionHasNoErrors();

    expect(WorkspaceDomain::sole()->cloudflare_errors)->toBe(['custom_origin_sni is not allowed']);
});

test('without Cloudflare credentials domains work exactly as before', function () {
    config(['services.cloudflare.api_token' => null]);
    Http::fake();

    $this->actingAs($this->owner)->post(route('brand.domain.store'), ['host' => 'tours.agency.com'])->assertSessionHasNoErrors();

    Http::assertNothingSent();
    expect(WorkspaceDomain::sole()->cloudflare_hostname_id)->toBeNull();
});

test('the refresh command updates hostnames that are not active yet', function () {
    Http::fake(['api.cloudflare.com/*' => Http::sequence()
        ->push(cloudflareResult())
        ->push(cloudflareResult(['status' => 'active', 'ssl' => ['status' => 'active']]))]);

    $this->actingAs($this->owner)->post(route('brand.domain.store'), ['host' => 'tours.agency.com']);

    $this->artisan('domains:refresh-cloudflare')->assertSuccessful();

    expect(WorkspaceDomain::sole()->cloudflare_ssl_status)->toBe('active');
});

test('platform admins see every hostname matched to its workspace and can delete orphans', function () {
    Http::fake([
        'api.cloudflare.com/*custom_hostnames?*' => Http::response(['result' => [
            cloudflareResult()['result'],
            ['id' => CF_ORPHAN_ID, 'hostname' => 'leftover.test.com', 'status' => 'pending', 'ssl' => ['status' => 'pending_validation']],
        ]]),
        'api.cloudflare.com/*custom_hostnames/*' => Http::response(['success' => true]),
    ]);

    $domain = new WorkspaceDomain(['host' => 'tours.agency.com', 'status' => 'pending', 'verification_token' => WorkspaceDomain::newToken()]);
    $domain->workspace_id = $this->workspace->id;
    $domain->cloudflare_hostname_id = CF_HOSTNAME_ID;
    $domain->save();

    $this->actingAs($this->owner)->get(route('admin.cloudflare-hostnames.index'))->assertForbidden();

    $admin = User::factory()->withWorkspace()->create();
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($admin)->get(route('admin.cloudflare-hostnames.index'))->assertInertia(fn (Assert $page) => $page
        ->where('enabled', true)
        ->has('hostnames', 2)
        ->where('hostnames.0.type', 'tour_domain')
        ->where('hostnames.0.account.email', $this->owner->email)
        ->where('hostnames.1.type', 'orphan'));

    $this->delete(route('admin.cloudflare-hostnames.destroy', CF_ORPHAN_ID))->assertRedirect();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/custom_hostnames/'.CF_ORPHAN_ID));

    $this->delete(route('admin.cloudflare-hostnames.destroy', CF_HOSTNAME_ID));
    expect($domain->refresh()->cloudflare_hostname_id)->toBeNull()->and($domain->host)->toBe('tours.agency.com');
});
