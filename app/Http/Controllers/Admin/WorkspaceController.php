<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use App\Services\Credits\CreditService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function __construct(protected EntitlementService $entitlements) {}

    public function index(Request $request): Response
    {
        $search = trim($request->string('q')->toString());
        $planFilter = $request->string('plan')->toString();
        $defaultPlan = $this->entitlements->defaultPlan();

        $workspaces = Workspace::query()
            ->with(['owner:id,name,email', 'currentSubscription.plan:id,ulid,name'])
            ->withCount(['members', 'properties', 'tours'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('owner', fn ($owner) => $owner->where('email', 'like', "%{$search}%"))))
            ->when($planFilter !== '', fn ($query) => $query->whereHas('currentSubscription.plan', fn ($plan) => $plan->where('ulid', $planFilter)))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $wallets = CreditWallet::whereIn('workspace_id', $workspaces->getCollection()->pluck('id'))->get()->keyBy('workspace_id');

        return Inertia::render('admin/Workspaces', [
            'workspaces' => $workspaces->through(fn (Workspace $workspace): array => [
                'id' => $workspace->ulid,
                'name' => $workspace->name,
                'owner' => ['name' => $workspace->owner->name, 'email' => $workspace->owner->email],
                'plan' => $workspace->currentSubscription->plan->name ?? $defaultPlan->name ?? null,
                'members_count' => (int) $workspace->getAttribute('members_count'),
                'properties_count' => (int) $workspace->getAttribute('properties_count'),
                'tours_count' => (int) $workspace->getAttribute('tours_count'),
                'credits' => max(0, (int) ($wallets[$workspace->id]->balance ?? 0) - (int) ($wallets[$workspace->id]->reserved ?? 0)),
                'credits_held' => (int) ($wallets[$workspace->id]->reserved ?? 0),
                'created_at' => $workspace->created_at?->toIso8601String(),
            ]),
            'plans' => $this->planOptions(),
            'filters' => ['q' => $search, 'plan' => $planFilter],
        ]);
    }

    public function show(Workspace $workspace, CreditService $credits): Response
    {
        $workspace->load(['owner:id,name,email', 'members:id,name,email']);
        $wallet = $credits->wallet($workspace->id);
        $current = $workspace->currentSubscription()->with('plan')->first();

        // Not called "workspace": that name is the shared current-workspace prop.
        return Inertia::render('admin/WorkspaceShow', [
            'account' => [
                'id' => $workspace->ulid,
                'name' => $workspace->name,
                'is_personal' => $workspace->is_personal,
                'domain' => $workspace->domain === null ? null : ['host' => $workspace->domain->host, 'status' => $workspace->domain->status],
                'owner' => ['name' => $workspace->owner->name, 'email' => $workspace->owner->email],
                'members' => $workspace->members->map(fn (User $member): array => [
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->membership->role->value,
                ])->values(),
                'created_at' => $workspace->created_at?->toIso8601String(),
            ],
            'entitlements' => $this->entitlements->summary($workspace->id),
            'subscription' => $current === null ? null : $this->subscription($current),
            'history' => $workspace->subscriptions()->with(['plan:id,name', 'assigner:id,name'])->latest('id')->limit(20)->get()
                ->map(fn (Subscription $subscription): array => $this->subscription($subscription))->values(),
            'credits' => [
                'balance' => $wallet->balance,
                'reserved' => $wallet->reserved,
                'available' => $wallet->available(),
                'recent' => CreditTransaction::where('workspace_id', $workspace->id)->with('user:id,name')->latest('id')->limit(15)->get()
                    ->map(fn (CreditTransaction $transaction): array => CreditController::transaction($transaction))->values(),
            ],
            'plans' => $this->planOptions(),
            'statuses' => array_map(fn (SubscriptionStatus $status): array => ['value' => $status->value, 'label' => $status->label()], array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $status): bool => $status->isCurrent())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function subscription(Subscription $subscription): array
    {
        return [
            'id' => $subscription->ulid,
            'plan' => $subscription->plan->name,
            'status' => $subscription->status->value,
            'status_label' => $subscription->status->label(),
            'provider' => $subscription->provider,
            'current_period_start' => $subscription->current_period_start?->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'auto_renew' => $subscription->auto_renew,
            'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
            'assigned_by' => $subscription->assigner?->name,
            'notes' => $subscription->notes,
            'created_at' => $subscription->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function planOptions(): array
    {
        return array_values(Plan::orderBy('sort_order')->orderBy('id')->get(['ulid', 'name', 'is_active'])
            ->map(fn (Plan $plan): array => ['value' => $plan->ulid, 'label' => $plan->name.($plan->is_active ? '' : ' (inactive)')])
            ->all());
    }
}
