<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Entitlement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\EntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrator-configured plans. Limits are read only through
 * EntitlementService, so editing a plan applies everywhere at once.
 */
class PlanController extends Controller
{
    public function __construct(protected EntitlementService $entitlements) {}

    public function index(): Response
    {
        $plans = Plan::orderBy('sort_order')->orderBy('id')->get();
        $counts = Subscription::current()->selectRaw('plan_id, count(distinct workspace_id) as total')->groupBy('plan_id')->toBase()->pluck('total', 'plan_id');

        return Inertia::render('admin/Plans', [
            'plans' => $plans->map(fn (Plan $plan): array => [
                ...$this->plan($plan),
                'workspaces' => (int) ($counts[$plan->id] ?? 0),
            ])->values(),
            'entitlementDefinitions' => $this->definitions(),
            'defaults' => [
                'currency' => config('plans.currency', 'USD'),
                'entitlements' => config('plans.defaults.0.entitlements', config('plans.unlimited')),
            ],
        ]);
    }

    public function store(PlanRequest $request): RedirectResponse
    {
        $plan = DB::transaction(function () use ($request): Plan {
            $plan = Plan::create($request->planAttributes());
            $this->keepSingleDefault($plan);

            return $plan;
        });

        $this->entitlements->forget();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plan :name created.', ['name' => $plan->name])]);

        return to_route('admin.plans.index');
    }

    public function update(PlanRequest $request, Plan $plan): RedirectResponse
    {
        $attributes = $request->planAttributes();

        if ($plan->is_default && (! $attributes['is_default'] || ! $attributes['is_active'])) {
            throw ValidationException::withMessages(['is_default' => __('Make another plan the default first.')]);
        }

        DB::transaction(function () use ($plan, $attributes): void {
            $plan->update($attributes);
            $this->keepSingleDefault($plan);
        });

        $this->entitlements->forget();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plan :name saved. Changes apply to every workspace on it.', ['name' => $plan->name])]);

        return to_route('admin.plans.index');
    }

    /**
     * Plans with subscriptions are kept for history; deactivate them instead.
     */
    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->is_default) {
            throw ValidationException::withMessages(['plan' => __('The default plan cannot be deleted.')]);
        }

        if ($plan->subscriptions()->exists()) {
            throw ValidationException::withMessages(['plan' => __('This plan has subscriptions. Deactivate it instead of deleting it.')]);
        }

        $plan->delete();
        $this->entitlements->forget();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plan deleted.')]);

        return to_route('admin.plans.index');
    }

    protected function keepSingleDefault(Plan $plan): void
    {
        if ($plan->is_default) {
            Plan::whereKeyNot($plan->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function plan(Plan $plan): array
    {
        return [
            'id' => $plan->ulid,
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_monthly' => $plan->price_monthly,
            'currency' => $plan->currency,
            'entitlements' => array_merge(
                array_fill_keys(array_map(fn (Entitlement $entitlement): string => $entitlement->value, Entitlement::cases()), null),
                $plan->entitlements,
            ),
            'is_default' => $plan->is_default,
            'is_active' => $plan->is_active,
            'sort_order' => $plan->sort_order,
        ];
    }

    /**
     * @return list<array{key: string, label: string, feature: bool}>
     */
    protected function definitions(): array
    {
        return array_map(fn (Entitlement $entitlement): array => [
            'key' => $entitlement->value,
            'label' => $entitlement->label().($entitlement === Entitlement::StorageMb ? ' (MB)' : ''),
            'feature' => $entitlement->isFeature(),
        ], Entitlement::cases());
    }
}
