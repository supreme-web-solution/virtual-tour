<?php

use App\Models\Plan;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->withWorkspace()->create();
    $this->admin->forceFill(['is_admin' => true])->save();

    $this->customer = User::factory()->withWorkspace()->create();
    $this->workspace = $this->customer->currentWorkspace;
});

/**
 * @return array<string, mixed>
 */
function planPayload(array $overrides = []): array
{
    return [
        'name' => 'Agency',
        'slug' => 'agency',
        'description' => 'For agencies',
        'price_monthly' => 4900,
        'currency' => 'usd',
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 5,
        'entitlements' => [...config('plans.unlimited'), 'properties' => 25, 'monthly_credits' => 200, 'api_access' => false],
        ...$overrides,
    ];
}

test('only platform administrators can open the admin console', function () {
    $this->actingAs($this->customer)->get(route('admin.dashboard'))->assertForbidden();
    $this->actingAs($this->customer)->get(route('admin.workspaces.index'))->assertForbidden();
    $this->actingAs($this->customer)->post(route('admin.workspaces.credits.store', $this->workspace), ['amount' => 100, 'reason' => 'x'])->assertForbidden();

    auth()->logout();
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

test('every admin page renders', function (string $route, string $component) {
    Plan::factory()->default()->create();

    $this->actingAs($this->admin)
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['admin.dashboard', 'admin/Dashboard'],
    ['admin.users.index', 'admin/Users'],
    ['admin.workspaces.index', 'admin/Workspaces'],
    ['admin.plans.index', 'admin/Plans'],
    ['admin.content.index', 'admin/Content'],
    ['admin.generations.index', 'admin/Generations'],
    ['admin.credits.index', 'admin/Credits'],
    ['admin.jobs.index', 'admin/FailedJobs'],
    ['admin.system', 'admin/System'],
]);

test('the system page reports configuration without revealing secrets', function () {
    config(['services.openai.api_key' => 'sk-test-super-secret', 'services.cloudinary.api_secret' => 'cloud-secret-value']);

    $response = $this->actingAs($this->admin)->get(route('admin.system'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('schedule', fn ($tasks) => collect($tasks)->contains('command', 'subscriptions:renew')));

    expect($response->getContent())->not->toContain('sk-test-super-secret')
        ->not->toContain('cloud-secret-value');
});

test('an admin can move a workspace to another plan and add credits', function () {
    $pro = Plan::factory()->with(['monthly_credits' => 300])->create(['name' => 'Pro']);
    $credits = app(CreditService::class);
    $before = $credits->wallet($this->workspace->id)->balance;

    $this->actingAs($this->admin)
        ->post(route('admin.workspaces.subscription.store', $this->workspace), ['plan_id' => $pro->ulid, 'status' => 'active', 'notes' => 'Paid by transfer'])
        ->assertSessionHasNoErrors();

    $this->post(route('admin.workspaces.credits.store', $this->workspace), ['amount' => 50, 'reason' => 'Goodwill'])
        ->assertSessionHasNoErrors();

    $subscription = $this->workspace->currentSubscription()->sole();
    expect($subscription->plan_id)->toBe($pro->id)
        ->and($subscription->assigned_by)->toBe($this->admin->id)
        ->and($subscription->notes)->toBe('Paid by transfer')
        ->and($credits->wallet($this->workspace->id)->balance)->toBe($before + 350);

    $this->get(route('admin.workspaces.show', $this->workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/WorkspaceShow')
            ->where('account.name', $this->workspace->name)
            ->where('workspace.can.manage', true)
            ->where('entitlements.plan.name', 'Pro')
            ->has('history', 1));

    $this->delete(route('admin.workspaces.subscription.destroy', $this->workspace))->assertSessionHasNoErrors();
    expect($this->workspace->currentSubscription()->exists())->toBeFalse();
});

test('plans are validated, keep a single default, and edits apply immediately', function () {
    $free = Plan::factory()->default()->with(['properties' => 1])->create();
    Property::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->post(route('admin.plans.store'), planPayload(['slug' => 'Bad Slug!', 'entitlements' => ['properties' => -1]]))
        ->assertSessionHasErrors(['slug', 'entitlements.properties', 'entitlements.video_studio']);

    $this->post(route('admin.plans.store'), planPayload(['is_default' => true]))->assertRedirect(route('admin.plans.index'));

    $agency = Plan::where('slug', 'agency')->sole();
    expect($agency->currency)->toBe('USD')
        ->and($agency->entitlements['properties'])->toBe(25)
        ->and($agency->is_default)->toBeTrue()
        ->and($free->fresh()->is_default)->toBeFalse();

    // The customer has no subscription, so the new default plan's limit applies at once.
    app(EntitlementService::class)->forget();
    $this->actingAs($this->customer)
        ->post(route('properties.store'), ['name' => 'Second', 'property_type' => 'villa', 'status' => 'active', 'area_unit' => 'sqm'])
        ->assertSessionHasNoErrors();
});

test('the default plan and plans with subscribers cannot be deleted', function () {
    $default = Plan::factory()->default()->create();
    $used = Plan::factory()->create();
    Subscription::factory()->create(['workspace_id' => $this->workspace->id, 'plan_id' => $used->id]);
    $unused = Plan::factory()->create();

    $this->actingAs($this->admin);
    $this->delete(route('admin.plans.destroy', $default))->assertSessionHasErrors('plan');
    $this->delete(route('admin.plans.destroy', $used))->assertSessionHasErrors('plan');
    $this->delete(route('admin.plans.destroy', $unused))->assertSessionHasNoErrors();

    $this->put(route('admin.plans.update', $default), planPayload(['slug' => $default->slug, 'is_default' => false]))
        ->assertSessionHasErrors('is_default');

    expect(Plan::count())->toBe(2);
});

test('admins can grant admin access but not remove their own', function () {
    $this->actingAs($this->admin)
        ->patch(route('admin.users.update', $this->customer), ['is_admin' => true])
        ->assertSessionHasNoErrors();

    $this->patch(route('admin.users.update', $this->admin), ['is_admin' => false])
        ->assertSessionHasErrors('is_admin');

    expect($this->customer->fresh()->is_admin)->toBeTrue()
        ->and($this->admin->fresh()->is_admin)->toBeTrue();
});

test('failed jobs can be listed and removed', function () {
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'ai-analysis',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\AI\\GenerateScenePlan', 'attempts' => 3]),
        'exception' => "RuntimeException: Provider timed out\n#0 /var/www/secret/path.php(12)",
        'failed_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.jobs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.data.0.job', 'GenerateScenePlan')
            ->where('jobs.data.0.exception', 'RuntimeException: Provider timed out'));

    $this->delete(route('admin.jobs.destroy', $uuid))->assertSessionHasNoErrors();
    expect(DB::table('failed_jobs')->count())->toBe(0);
});

test('accounts listed in ADMIN_EMAILS reach the admin console without a flag', function () {
    config(['app.admin_emails' => 'Owner@Example.com, other@example.com']);

    $listed = User::factory()->create(['email' => 'owner@example.com']);

    expect($listed->is_admin)->toBeTrue();

    $this->actingAs($listed)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($this->customer)->get(route('admin.dashboard'))->assertForbidden();
});

test('a listed admin email grants access whether or not it is verified', function () {
    config(['app.admin_emails' => 'owner@example.com']);

    $owner = User::factory()->unverified()->create(['email' => 'owner@example.com']);

    expect($owner->is_admin)->toBeTrue();
    $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();
});

test('admin access granted by email cannot be revoked in the console', function () {
    config(['app.admin_emails' => 'owner@example.com']);

    $listed = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($this->admin)
        ->patch(route('admin.users.update', $listed), ['is_admin' => false])
        ->assertSessionHasErrors('is_admin');
});
