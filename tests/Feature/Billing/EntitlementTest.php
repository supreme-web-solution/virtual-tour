<?php

use App\Contracts\Media\MediaStorage;
use App\Enums\WorkspaceRole;
use App\Jobs\Media\DeleteStoredMedia;
use App\Models\MediaAsset;
use App\Models\Plan;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VirtualTour;
use App\Services\Billing\EntitlementService;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeMediaStorage;

beforeEach(function () {
    $this->storage = new FakeMediaStorage;
    $this->app->instance(MediaStorage::class, $this->storage);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
});

/**
 * Put the test workspace on a plan with these entitlements (others unlimited).
 */
function onPlan(array $entitlements, string $name = 'Starter'): Plan
{
    $plan = Plan::factory()->with($entitlements)->create(['name' => $name]);
    Subscription::factory()->create(['workspace_id' => test()->workspace->id, 'plan_id' => $plan->id]);
    app(EntitlementService::class)->forget();

    return $plan;
}

test('without any plan configured nothing is limited', function () {
    Property::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->post(route('properties.store'), ['name' => 'Fourth', 'property_type' => 'villa', 'status' => 'active', 'area_unit' => 'sqm'])
        ->assertSessionHasNoErrors();

    expect(Property::count())->toBe(4);
});

test('a property over the plan limit is refused with an explanation', function () {
    onPlan(['properties' => 1]);
    Property::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->post(route('properties.store'), ['name' => 'Second', 'property_type' => 'villa', 'status' => 'active', 'area_unit' => 'sqm'])
        ->assertSessionHasErrors(['plan' => 'Your Starter plan allows 1 properties. Upgrade your plan to add more.']);

    expect(Property::count())->toBe(1);
});

test('deleted properties free up room again', function () {
    onPlan(['properties' => 1]);
    Property::factory()->create(['workspace_id' => $this->workspace->id])->delete();

    $this->actingAs($this->owner)
        ->post(route('properties.store'), ['name' => 'Replacement', 'property_type' => 'villa', 'status' => 'active', 'area_unit' => 'sqm'])
        ->assertSessionHasNoErrors();
});

test('tours and scenes per tour are limited, including scenes added in one request', function () {
    onPlan(['tours' => 1, 'scenes_per_tour' => 2]);
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    TourScene::factory()->forTour($tour)->create();
    $panoramas = MediaAsset::factory()->panorama()->count(2)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->post(route('tours.store'), ['title' => 'Second tour'])
        ->assertSessionHasErrors('plan');

    $this->postJson(route('tours.scenes.store', $tour), ['media_asset_ids' => $panoramas->pluck('ulid')->all()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plan');

    $this->postJson(route('tours.scenes.store', $tour), ['media_asset_ids' => [$panoramas->first()->ulid]])
        ->assertCreated();
});

test('pending invitations count towards team members', function () {
    onPlan(['team_members' => 2]);

    $this->actingAs($this->owner)
        ->post(route('team.invite'), ['email' => 'first@example.com', 'role' => 'editor'])
        ->assertSessionHasNoErrors();

    $this->post(route('team.invite'), ['email' => 'second@example.com', 'role' => 'editor'])
        ->assertSessionHasErrors('plan');

    // Re-sending the same invitation replaces it rather than using another seat.
    $this->post(route('team.invite'), ['email' => 'first@example.com', 'role' => 'viewer'])
        ->assertSessionHasNoErrors();
});

test('an upload that would overflow storage is refused and removed from storage', function () {
    Queue::fake();
    onPlan(['storage_mb' => 1]);
    $publicId = $this->storage->put($this->workspace->storageFolder().'/big', bytes: 2 * 1024 * 1024);

    $this->actingAs($this->owner)
        ->postJson(route('media.store'), ['public_id' => $publicId, 'resource_type' => 'image', 'asset_type' => 'photo', 'original_filename' => 'big.jpg'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plan');

    expect(MediaAsset::count())->toBe(0);
    Queue::assertPushed(DeleteStoredMedia::class);
});

test('uploads cannot even start once storage is full', function () {
    onPlan(['storage_mb' => 1]);
    MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'file_size' => 1024 * 1024]);

    $this->actingAs($this->owner)
        ->postJson(route('media.signature'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plan');
});

test('videos per month count deleted videos, and the studio can be switched off', function () {
    onPlan(['videos_per_month' => 1]);
    VideoProject::factory()->inWorkspace($this->workspace)->create()->delete();
    $photo = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    $payload = ['title' => 'Teaser', 'style' => 'property_walkthrough', 'aspect_ratio' => '16:9', 'duration' => 10, 'source_ids' => [$photo->ulid], 'soundtrack_mode' => 'none'];

    $this->actingAs($this->owner)->post(route('videos.store'), $payload)->assertSessionHasErrors('plan');

    Subscription::query()->delete();
    onPlan(['video_studio' => false], 'Tours only');

    $this->post(route('videos.store'), $payload)->assertSessionHasErrors(['plan' => 'AI Video Studio is not included in your Tours only plan. Upgrade your plan to use it.']);
});

test('the powered-by badge can only be removed on plans that include it, and returns after a downgrade', function () {
    onPlan(['remove_branding' => false]);
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create([
        'branding_settings' => ['show_platform_branding' => false],
    ]);

    $this->actingAs($this->owner)
        ->patchJson(route('tours.update', $tour), ['branding' => ['show_platform_branding' => false]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plan');

    $this->get(route('tours.public', ['publicId' => $tour->public_id]))
        ->assertInertia(fn (Assert $page) => $page->where('tour.branding.show_platform_branding', true));

    Subscription::query()->delete();
    onPlan(['remove_branding' => true], 'Pro');

    $this->patchJson(route('tours.update', $tour), ['branding' => ['show_platform_branding' => false]])->assertOk();
    $this->get(route('tours.public', ['publicId' => $tour->public_id]))
        ->assertInertia(fn (Assert $page) => $page->where('tour.branding.show_platform_branding', false));
});

test('pages know which features the plan includes', function () {
    onPlan(['remove_branding' => false, 'advanced_analytics' => true], 'Starter');

    $this->actingAs($this->owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('workspace.plan.name', 'Starter')
            ->where('workspace.plan.features.remove_branding', false)
            ->where('workspace.plan.features.advanced_analytics', true));
});

test('owners see their plan and usage, other members cannot open billing', function () {
    onPlan(['properties' => 5], 'Starter');
    Property::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/Index')
            ->where('summary.plan.name', 'Starter')
            ->where('summary.limits.0.key', 'properties')
            ->where('summary.limits.0.used', 2)
            ->where('summary.limits.0.limit', 5));

    $editor = User::factory()->create();
    $this->workspace->addMember($editor, WorkspaceRole::Editor);
    $editor->switchWorkspace($this->workspace);

    $this->actingAs($editor)->get(route('billing.index'))->assertForbidden();
});
