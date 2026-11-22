<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\SubscriptionService;
use App\Services\Credits\CreditService;

beforeEach(function () {
    $this->free = Plan::factory()->default()->with(['monthly_credits' => 10, 'properties' => 1])->create(['name' => 'Free']);
    $this->pro = Plan::factory()->with(['monthly_credits' => 500])->create(['name' => 'Pro']);
    $this->subscriptions = app(SubscriptionService::class);
    $this->credits = app(CreditService::class);
});

test('a new account starts on the default plan with its monthly credits', function () {
    config(['ai.credits.starter_balance' => 0]);

    $this->post(route('register.store'), [
        'name' => 'Ada Obi',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $workspace = User::where('email', 'ada@example.com')->sole()->currentWorkspace;

    expect($workspace->currentSubscription->plan->is($this->free))->toBeTrue()
        ->and($workspace->currentSubscription->provider)->toBe('default')
        ->and($this->credits->wallet($workspace->id)->balance)->toBe(10);
});

test('assigning a plan replaces the current one and grants its credits once', function () {
    $workspace = Workspace::factory()->create();
    $old = $this->subscriptions->assign($workspace, $this->free, null);

    $new = $this->subscriptions->assign($workspace, $this->pro, null, SubscriptionStatus::Active, now()->addYear(), 'Annual deal');

    expect($old->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($workspace->currentSubscription()->sole()->is($new))->toBeTrue()
        ->and($new->current_period_end->isSameDay(now()->addYear()))->toBeTrue()
        ->and($this->credits->wallet($workspace->id)->balance)->toBe(510)
        ->and(app(EntitlementService::class)->plan($workspace->id)->is($this->pro))->toBeTrue();
});

test('renewal starts the next period and grants credits exactly once', function () {
    $workspace = Workspace::factory()->create();
    $subscription = $this->subscriptions->assign($workspace, $this->pro, null);

    $this->travelTo(now()->addMonth()->addHour());
    $this->subscriptions->renewDue();
    $this->subscriptions->renewDue();

    expect($subscription->fresh()->current_period_end->isFuture())->toBeTrue()
        ->and($subscription->periods()->count())->toBe(2)
        ->and($this->credits->wallet($workspace->id)->balance)->toBe(1000);
});

test('missed renewals skip to the current period instead of stacking credits', function () {
    $workspace = Workspace::factory()->create();
    $subscription = $this->subscriptions->assign($workspace, $this->pro, null);

    $this->travelTo(now()->addMonths(3)->addDay());
    $this->subscriptions->renewDue();

    $subscription->refresh();
    expect($subscription->current_period_start->isPast())->toBeTrue()
        ->and($subscription->current_period_end->isFuture())->toBeTrue()
        ->and($this->credits->wallet($workspace->id)->balance)->toBe(1000);
});

test('cancelling falls back to the default plan, and plan-less workspaces get it on renewal', function () {
    $workspace = Workspace::factory()->create();
    $subscription = $this->subscriptions->assign($workspace, $this->pro, null);

    $this->subscriptions->cancel($subscription, null);
    expect(app(EntitlementService::class)->plan($workspace->id)->is($this->free))->toBeTrue();

    $result = $this->subscriptions->renewDue();

    expect($result['started'])->toBe(1)
        ->and(Subscription::where('workspace_id', $workspace->id)->current()->sole()->plan_id)->toBe($this->free->id);
});
