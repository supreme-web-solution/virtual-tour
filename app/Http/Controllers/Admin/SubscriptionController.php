<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Manual plan assignment while checkout is not built.
 */
class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptions) {}

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string', Rule::exists(Plan::class, 'ulid')],
            'status' => ['required', Rule::in(SubscriptionStatus::currentValues())],
            'period_end' => ['nullable', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $admin */
        $admin = $request->user();
        $plan = Plan::where('ulid', $data['plan_id'])->firstOrFail();

        $this->subscriptions->assign(
            $workspace,
            $plan,
            $admin,
            SubscriptionStatus::from($data['status']),
            isset($data['period_end']) ? Carbon::parse($data['period_end'])->endOfDay() : null,
            $data['notes'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':workspace is now on the :plan plan.', ['workspace' => $workspace->name, 'plan' => $plan->name])]);

        return back();
    }

    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        $subscription = $workspace->currentSubscription()->with('plan')->firstOrFail();

        /** @var User $admin */
        $admin = $request->user();
        $this->subscriptions->cancel($subscription, $admin);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription cancelled. The workspace is back on the default plan.')]);

        return back();
    }
}
