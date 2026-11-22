<?php

use App\Contracts\Media\MediaStorage;
use App\Enums\AssetType;
use App\Enums\TourVisibility;
use App\Enums\WorkspaceRole;
use App\Models\MediaAsset;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VirtualTour;
use App\Models\WorkspaceDomain;
use App\Services\Billing\EntitlementService;
use App\Services\Domains\BrandPresenter;
use App\Services\Domains\DnsLookup;
use App\Services\Domains\PublicUrl;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeDnsLookup;
use Tests\Fakes\FakeMediaStorage;

beforeEach(function () {
    config([
        'app.url' => 'https://app.platform.com',
        'app.name' => 'TourPlatform',
        'domains.cname_target' => 'domains.platform.com',
        'domains.app_hosts' => ['localhost'],
    ]);

    $this->dns = new FakeDnsLookup;
    $this->app->instance(DnsLookup::class, $this->dns);
    $this->app->instance(MediaStorage::class, new FakeMediaStorage);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'Seaside Villa']);
    TourScene::factory()->forTour($this->tour)->create();
});

function domainPlan(array $entitlements): void
{
    Subscription::query()->where('workspace_id', test()->workspace->id)->delete();
    $plan = Plan::factory()->with($entitlements)->create();
    Subscription::factory()->create(['workspace_id' => test()->workspace->id, 'plan_id' => $plan->id]);
    app(EntitlementService::class)->forget();
    app(PublicUrl::class)->forget();
    app(BrandPresenter::class)->forget();
}

/**
 * Connect tours.agency.example.com and publish correct DNS for it.
 */
function verifiedDomain(string $host = 'tours.agency.com'): WorkspaceDomain
{
    test()->actingAs(test()->owner)->post(route('brand.domain.store'), ['host' => "https://{$host}/"])->assertSessionHasNoErrors();

    $domain = WorkspaceDomain::where('host', $host)->sole();
    test()->dns->txtRecords["_tour-verify.{$host}"] = [$domain->verification_token];
    test()->dns->cnameRecords[$host] = ['domains.platform.com'];
    test()->post(route('brand.domain.verify'))->assertSessionHasNoErrors();
    Cache::flush();

    return $domain->refresh();
}

test('a domain is verified once its TXT record and CNAME are in place', function () {
    $this->actingAs($this->owner)
        ->post(route('brand.domain.store'), ['host' => 'Tours.Agency.com'])
        ->assertSessionHasNoErrors();

    $domain = WorkspaceDomain::sole();
    expect($domain->host)->toBe('tours.agency.com')->and($domain->status)->toBe('pending');

    $this->post(route('brand.domain.verify'));
    expect($domain->refresh()->status)->toBe('pending')->and($domain->last_error)->toContain('TXT record');

    $this->dns->txtRecords['_tour-verify.tours.agency.com'] = [$domain->verification_token];
    $this->post(route('brand.domain.verify'));
    expect($domain->refresh()->status)->toBe('pending')->and($domain->last_error)->toContain('CNAME');

    $this->dns->cnameRecords['tours.agency.com'] = ['domains.platform.com'];
    $this->post(route('brand.domain.verify'));
    expect($domain->refresh()->status)->toBe('verified')->and($domain->verified_at)->not->toBeNull();
});

test('a verified domain whose DNS disappears stops being served', function () {
    $domain = verifiedDomain();
    $this->dns->txtRecords = [];
    $this->travel(31)->minutes();

    $this->artisan('domains:verify')->assertSuccessful();

    expect($domain->refresh()->status)->toBe('failed');
    $this->get("https://tours.agency.com/t/{$this->tour->public_id}")->assertNotFound();
});

test('domains are validated and cannot be claimed twice', function (string $host, string $message) {
    $other = User::factory()->withWorkspace()->create();
    $claimed = new WorkspaceDomain(['host' => 'taken.agency.com', 'status' => 'pending', 'verification_token' => 'x']);
    $claimed->workspace_id = $other->current_workspace_id;
    $claimed->save();

    $this->actingAs($this->owner)
        ->post(route('brand.domain.store'), ['host' => $host])
        ->assertSessionHasErrors(['host' => $message]);
})->with([
    'not a domain' => ['not a domain', 'Enter a domain name such as tours.youragency.com.'],
    'ip address' => ['93.184.215.14', 'Enter a domain name such as tours.youragency.com.'],
    'reserved' => ['tours.agency.test', 'Use a public domain name you own.'],
    'platform domain' => ['evil.app.platform.com', 'This domain belongs to the platform itself.'],
    'taken' => ['taken.agency.com', 'This domain is already connected to another workspace.'],
]);

test('custom domains need a plan that includes them, and only owners and admins manage them', function () {
    domainPlan(['custom_domain' => false]);

    $this->actingAs($this->owner)
        ->post(route('brand.domain.store'), ['host' => 'tours.agency.com'])
        ->assertSessionHasErrors('plan');

    $editor = User::factory()->create();
    $this->workspace->addMember($editor, WorkspaceRole::Editor);
    $editor->switchWorkspace($this->workspace);

    $this->actingAs($editor)->get(route('brand.index'))->assertForbidden();
    $this->actingAs($editor)->post(route('brand.domain.store'), ['host' => 'tours.agency.com'])->assertForbidden();
});

test('a verified domain serves its own workspace\'s public pages and nothing else', function () {
    verifiedDomain();
    $foreign = VirtualTour::factory()->published()->create();
    auth()->logout();

    $this->get("https://tours.agency.com/t/{$this->tour->public_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('public/TourViewer'));

    $this->get("https://tours.agency.com/t/{$foreign->public_id}")->assertNotFound();
    $this->get('https://tours.agency.com/login')->assertNotFound();
    $this->get('https://tours.agency.com/dashboard')->assertNotFound();
    $this->get('https://unknown.example.org/t/'.$this->tour->public_id)->assertNotFound();

    $this->postJson("https://tours.agency.com/t/{$foreign->public_id}/visits", ['visitor_id' => '0b8f0c8e-2a5b-4f39-9c43-7d4b1f2e9a10'])->assertNotFound();
});

test('the domain home page lists only public tours and videos', function () {
    verifiedDomain();
    VirtualTour::factory()->inWorkspace($this->workspace)->published(TourVisibility::Unlisted)->create(['title' => 'Hidden Loft']);
    VideoProject::factory()->inWorkspace($this->workspace)->create([
        'title' => 'Teaser',
        'final_asset_id' => MediaAsset::factory()->create(['workspace_id' => $this->workspace->id])->id,
        'published_at' => now(),
        'visibility' => TourVisibility::Public,
    ]);
    auth()->logout();

    $this->get('https://tours.agency.com/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Showcase')
            ->has('tours', 1)
            ->where('tours.0.title', 'Seaside Villa')
            ->where('tours.0.url', "https://tours.agency.com/t/{$this->tour->public_id}/{$this->tour->slug}")
            ->has('videos', 1));
});

test('share links use the custom domain once it is verified', function () {
    $this->actingAs($this->owner)
        ->get(route('tours.edit', $this->tour))
        ->assertInertia(fn (Assert $page) => $page->where('tour.public_url', "https://app.platform.com/t/{$this->tour->public_id}/{$this->tour->slug}"));

    verifiedDomain();

    $this->get(route('tours.edit', $this->tour))
        ->assertInertia(fn (Assert $page) => $page->where('tour.public_url', "https://tours.agency.com/t/{$this->tour->public_id}/{$this->tour->slug}"));
});

test('after a downgrade the domain redirects visitors to the platform address', function () {
    verifiedDomain();
    domainPlan(['custom_domain' => false]);
    Cache::flush();
    auth()->logout();

    $this->get("https://tours.agency.com/t/{$this->tour->public_id}")
        ->assertRedirect("https://app.platform.com/t/{$this->tour->public_id}");
});

test('certificates are only allowed for verified domains', function () {
    $this->get(route('domains.tls-check', ['domain' => 'tours.agency.com']))->assertNotFound();

    verifiedDomain();

    $this->get(route('domains.tls-check', ['domain' => 'tours.agency.com']))->assertOk();
    $this->get(route('domains.tls-check', ['domain' => 'other.agency.com']))->assertNotFound();
});

test('white-label replaces the platform name on public pages', function () {
    $this->actingAs($this->owner)
        ->patch(route('brand.update'), ['display_name' => 'Lagos Luxe Homes', 'primary_color' => '#0f766e', 'website' => 'https://lagosluxe.com'])
        ->assertSessionHasNoErrors();

    domainPlan(['remove_branding' => false]);
    $this->get(route('tours.public', ['publicId' => $this->tour->public_id]))
        ->assertInertia(fn (Assert $page) => $page->where('siteName', 'TourPlatform')->where('tour.branding.show_platform_branding', true));

    domainPlan(['remove_branding' => true]);
    $response = $this->get(route('tours.public', ['publicId' => $this->tour->public_id]));

    $response->assertInertia(fn (Assert $page) => $page->where('siteName', 'Lagos Luxe Homes'));
    expect($response->getContent())->toContain('<meta property="og:site_name" content="Lagos Luxe Homes">');
});

test('the workspace logo is the default logo for tours and videos', function () {
    $logo = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'asset_type' => AssetType::Logo]);

    $this->actingAs($this->owner)
        ->patch(route('brand.update'), ['logo_asset_id' => $logo->ulid])
        ->assertSessionHasNoErrors();

    $this->get(route('tours.public', ['publicId' => $this->tour->public_id]))
        ->assertInertia(fn (Assert $page) => $page->where('tour.branding.logo_url', "https://storage.test/w400/{$logo->cloudinary_public_id}.jpg"));

    $foreignLogo = MediaAsset::factory()->create();
    $this->patch(route('brand.update'), ['logo_asset_id' => $foreignLogo->ulid, 'primary_color' => 'teal'])
        ->assertSessionHasErrors(['logo_asset_id', 'primary_color']);
});

test('video pages on a custom domain keep their links on that domain', function () {
    verifiedDomain();
    $video = VideoProject::factory()->inWorkspace($this->workspace)->create([
        'final_asset_id' => MediaAsset::factory()->create(['workspace_id' => $this->workspace->id])->id,
        'published_at' => now(),
        'visibility' => TourVisibility::Public,
    ]);
    auth()->logout();

    $this->get("https://tours.agency.com/v/{$video->public_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('video.events_url', "/v/{$video->public_id}/events")
            ->where('video.download_url', "/v/{$video->public_id}/download"));
});
