<?php

use App\Contracts\Media\MediaStorage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\Billing\EntitlementService;
use App\Services\Domains\BrandPresenter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeMediaStorage;

beforeEach(function () {
    $this->app->instance(MediaStorage::class, new FakeMediaStorage);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create();
    TourScene::factory()->forTour($this->tour)->create();
});

function planWith(array $entitlements): void
{
    Subscription::query()->where('workspace_id', test()->workspace->id)->delete();
    $plan = Plan::factory()->with($entitlements)->create();
    Subscription::factory()->create(['workspace_id' => test()->workspace->id, 'plan_id' => $plan->id]);
    app(EntitlementService::class)->forget();
    app(BrandPresenter::class)->forget();
}

function saveTracking(array $data): void
{
    test()->actingAs(test()->owner)->patch(route('brand.update'), $data);
    test()->workspace->refresh();
    app(BrandPresenter::class)->forget();
    auth()->logout();
}

test('tracking IDs are saved in upper case on a plan that includes them', function () {
    planWith(['tracking_scripts' => true]);

    saveTracking(['ga4_id' => 'g-abc1234567', 'gtm_id' => 'gtm-abcd123']);

    expect($this->workspace->brand())->toMatchArray(['ga4_id' => 'G-ABC1234567', 'gtm_id' => 'GTM-ABCD123']);
});

test('malformed tracking IDs are rejected', function () {
    planWith(['tracking_scripts' => true]);

    $this->actingAs($this->owner)
        ->patch(route('brand.update'), ['ga4_id' => '<script>alert(1)</script>', 'gtm_id' => 'nope'])
        ->assertSessionHasErrors(['ga4_id', 'gtm_id']);
});

test('tracking IDs are not kept without the plan feature', function () {
    planWith(['tracking_scripts' => false]);

    saveTracking(['ga4_id' => 'G-ABC1234567']);

    expect($this->workspace->brand()['ga4_id'])->toBeNull();
});

test('the public page carries the tracking IDs only on a plan that includes them', function () {
    planWith(['tracking_scripts' => true]);
    saveTracking(['ga4_id' => 'G-ABC1234567']);

    $this->get(route('tours.public', ['publicId' => $this->tour->public_id]))
        ->assertInertia(fn (Assert $page) => $page->where('tracking', ['ga4' => 'G-ABC1234567', 'gtm' => null]));

    planWith(['tracking_scripts' => false]);

    $this->get(route('tours.public', ['publicId' => $this->tour->public_id]))
        ->assertInertia(fn (Assert $page) => $page->where('tracking', null));
});

test('an MLS-ready link removes branding, contact details and the enquiry form', function () {
    planWith(['remove_branding' => true, 'tracking_scripts' => true]);
    saveTracking(['ga4_id' => 'G-ABC1234567']);

    $this->get(route('tours.public', ['publicId' => $this->tour->public_id, 'mls' => 1]))
        ->assertOk()
        ->assertSee('noindex', false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('unbranded', true)
            ->where('tracking', null)
            ->where('tour.branding.logo_url', null)
            ->where('tour.branding.contact', null)
            ->where('tour.branding.show_platform_branding', false)
            ->where('tour.lead', null));
});

test('the MLS-ready link is ignored on a plan without white-label', function () {
    planWith(['remove_branding' => false]);

    $this->get(route('tours.public', ['publicId' => $this->tour->public_id, 'mls' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('unbranded', false));
});
