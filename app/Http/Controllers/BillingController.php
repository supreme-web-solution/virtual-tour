<?php

namespace App\Http\Controllers;

use App\Enums\Entitlement;
use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Credits\CreditService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan, usage and credits for the current workspace. Checkout is not built
 * yet, so changing plan is done by contacting the platform team.
 */
class BillingController extends Controller
{
    public function index(Request $request, EntitlementService $entitlements, CreditService $credits): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_unless($workspace !== null && (bool) $user->roleIn($workspace)?->canManage(), 403);

        $subscription = $workspace->currentSubscription;
        $wallet = $credits->wallet($workspace->id);
        $current = $entitlements->plan($workspace->id);

        return Inertia::render('billing/Index', [
            'summary' => $entitlements->summary($workspace->id),
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->status->value,
                'status_label' => $subscription->status->label(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'auto_renew' => $subscription->auto_renew,
            ],
            'credits' => [
                'balance' => $wallet->balance,
                'reserved' => $wallet->reserved,
                'available' => $wallet->available(),
                'recent' => CreditTransaction::where('workspace_id', $workspace->id)
                    ->whereIn('transaction_type', ['grant', 'charge'])
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (CreditTransaction $transaction): array => [
                        'id' => $transaction->id,
                        'type' => $transaction->transaction_type,
                        'amount' => $transaction->amount,
                        'description' => $transaction->description,
                        'created_at' => $transaction->created_at->toIso8601String(),
                    ])->values(),
            ],
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Plan $plan): array => [
                    'id' => $plan->ulid,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'price_monthly' => $plan->price_monthly,
                    'currency' => $plan->currency,
                    'is_current' => $current?->is($plan) ?? false,
                    'limits' => array_map(fn (Entitlement $entitlement): array => [
                        'key' => $entitlement->value,
                        'label' => $entitlement->label(),
                        'value' => $plan->entitlement($entitlement),
                    ], Entitlement::cases()),
                ])->values(),
            'contactEmail' => config('mail.from.address'),
        ]);
    }
}
