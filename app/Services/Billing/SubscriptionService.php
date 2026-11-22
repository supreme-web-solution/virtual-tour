<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Services\Credits\CreditService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Plan assignment and renewal. Checkout is not built yet: an admin assigns
 * plans ("manual" provider) and every workspace starts on the default plan.
 * A payment provider can later create subscriptions through assign().
 */
class SubscriptionService
{
    public function __construct(
        protected CreditService $credits,
        protected EntitlementService $entitlements,
        protected ActivityLogger $activity,
    ) {}

    /**
     * Put a new workspace on the default plan, if plans are configured.
     */
    public function startDefault(Workspace $workspace): ?Subscription
    {
        $plan = $this->entitlements->defaultPlan();

        if ($plan === null || $workspace->subscriptions()->current()->exists()) {
            return null;
        }

        return $this->assign($workspace, $plan, null, provider: 'default');
    }

    /**
     * Switch the workspace to a plan. The previous subscription is cancelled
     * and the new plan's monthly credits are granted for the first period.
     */
    public function assign(
        Workspace $workspace,
        Plan $plan,
        ?User $by,
        SubscriptionStatus $status = SubscriptionStatus::Active,
        ?CarbonInterface $periodEnd = null,
        ?string $notes = null,
        string $provider = 'manual',
    ): Subscription {
        $subscription = DB::transaction(function () use ($workspace, $plan, $by, $status, $periodEnd, $notes, $provider): Subscription {
            $workspace->subscriptions()->current()->lockForUpdate()->get()
                ->each(fn (Subscription $old) => $old->update([
                    'status' => SubscriptionStatus::Cancelled,
                    'cancelled_at' => now(),
                    'auto_renew' => false,
                ]));

            $start = now();
            $end = $periodEnd ?? $start->copy()->addMonthsNoOverflow((int) config('plans.period_months', 1));

            $subscription = new Subscription([
                'plan_id' => $plan->id,
                'status' => $status,
                'provider' => $provider,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'auto_renew' => true,
                'assigned_by' => $by?->id,
                'notes' => $notes,
            ]);
            $subscription->workspace_id = $workspace->id;
            $subscription->save();

            $this->startPeriod($subscription, $start, $end);

            return $subscription;
        });

        $this->entitlements->forget($workspace->id);
        $this->activity->log($workspace->id, $by, 'subscription.assigned', "Plan changed to {$plan->name}", $subscription);

        return $subscription;
    }

    /**
     * Stop a subscription. The workspace falls back to the default plan.
     */
    public function cancel(Subscription $subscription, ?User $by): void
    {
        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'auto_renew' => false,
        ]);

        $this->entitlements->forget($subscription->workspace_id);
        $this->activity->log($subscription->workspace_id, $by, 'subscription.cancelled', "Plan {$subscription->plan->name} cancelled", $subscription);
    }

    /**
     * Roll every due subscription into its next period (granting that
     * period's credits once), and give plan-less workspaces the default plan.
     *
     * @return array{renewed: int, started: int}
     */
    public function renewDue(): array
    {
        $renewed = 0;

        Subscription::current()
            ->where('auto_renew', true)
            ->where('current_period_end', '<=', now())
            ->with('plan')
            ->each(function (Subscription $subscription) use (&$renewed): void {
                DB::transaction(function () use ($subscription): void {
                    $subscription = Subscription::lockForUpdate()->with('plan')->findOrFail($subscription->id);

                    if ($subscription->current_period_end === null || $subscription->current_period_end->isFuture()) {
                        return;
                    }

                    // If renewals were missed, skip to the period containing today: credits are not stacked.
                    $months = (int) config('plans.period_months', 1);
                    $start = $subscription->current_period_end->copy();

                    while ($start->copy()->addMonthsNoOverflow($months)->lessThanOrEqualTo(now())) {
                        $start = $start->addMonthsNoOverflow($months);
                    }

                    $end = $start->copy()->addMonthsNoOverflow($months);
                    $subscription->update(['current_period_start' => $start, 'current_period_end' => $end]);
                    $this->startPeriod($subscription, $start, $end);
                });

                $renewed++;
            });

        $started = 0;

        if ($this->entitlements->defaultPlan() !== null) {
            Workspace::whereDoesntHave('subscriptions', fn ($query) => $query->current())
                ->each(function (Workspace $workspace) use (&$started): void {
                    if ($this->startDefault($workspace) !== null) {
                        $started++;
                    }
                });
        }

        $this->entitlements->forget();

        return ['renewed' => $renewed, 'started' => $started];
    }

    protected function startPeriod(Subscription $subscription, CarbonInterface $start, CarbonInterface $end): SubscriptionPeriod
    {
        $period = $subscription->periods()->firstOrCreate(['starts_at' => $start], ['ends_at' => $end]);

        $plan = $subscription->loadMissing('plan')->plan;
        $credits = $plan->monthlyCredits();

        if ($credits > 0 && (int) $period->credits_granted === 0) {
            $this->credits->grant($subscription->workspace_id, $credits, "{$plan->name} plan monthly credits", null, $period);
            $period->update(['credits_granted' => $credits]);
        }

        return $period;
    }
}
