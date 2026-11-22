<?php

namespace App\Services\Billing;

use App\Enums\Entitlement;
use App\Exceptions\PlanLimitReachedException;
use App\Models\MediaAsset;
use App\Models\Plan;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\VideoProject;
use App\Models\VirtualTour;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\DB;

/**
 * The single place that knows what a workspace's plan allows and how much
 * of it is used. Controllers and services ask here instead of checking
 * plans themselves.
 *
 * When no plan exists at all (fresh install, test suite) nothing is limited.
 */
class EntitlementService
{
    /** @var array<int, Plan|null> */
    protected array $plans = [];

    protected ?Plan $defaultPlan = null;

    protected bool $defaultResolved = false;

    /**
     * The plan in effect: the current subscription's, else the default plan.
     */
    public function plan(int $workspaceId): ?Plan
    {
        if (! array_key_exists($workspaceId, $this->plans)) {
            $subscription = Subscription::where('workspace_id', $workspaceId)->current()->latest('id')->with('plan')->first();
            $this->plans[$workspaceId] = $subscription->plan ?? $this->defaultPlan();
        }

        return $this->plans[$workspaceId];
    }

    public function defaultPlan(): ?Plan
    {
        if (! $this->defaultResolved) {
            $this->defaultPlan = Plan::where('is_active', true)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')->first();
            $this->defaultResolved = true;
        }

        return $this->defaultPlan;
    }

    /**
     * Forget memoised plans (after a subscription or plan changes).
     */
    public function forget(?int $workspaceId = null): void
    {
        if ($workspaceId === null) {
            $this->plans = [];
            $this->defaultResolved = false;
            $this->defaultPlan = null;

            return;
        }

        unset($this->plans[$workspaceId]);
    }

    /**
     * A numeric limit; null means unlimited.
     */
    public function limit(int $workspaceId, Entitlement $entitlement): ?int
    {
        $plan = $this->plan($workspaceId);

        if ($plan === null) {
            $value = config("plans.unlimited.{$entitlement->value}");

            return $value === null ? null : (int) $value;
        }

        $value = $plan->entitlement($entitlement);

        return is_bool($value) ? null : $value;
    }

    /**
     * Whether the workspace's plan lets it use AI without spending credits.
     * A workspace without a plan never gets this.
     */
    public function hasUnlimitedCredits(int $workspaceId): bool
    {
        $plan = $this->plan($workspaceId);

        return $plan !== null && (bool) $plan->entitlement(Entitlement::UnlimitedCredits);
    }

    public function allows(int $workspaceId, Entitlement $feature): bool
    {
        $plan = $this->plan($workspaceId);

        return $plan === null ? true : (bool) $plan->entitlement($feature);
    }

    /**
     * Current usage of a metered limit. Storage is reported in bytes.
     */
    public function usage(int $workspaceId, Entitlement $entitlement, ?VirtualTour $tour = null): int
    {
        return match ($entitlement) {
            Entitlement::Properties => Property::where('workspace_id', $workspaceId)->count(),
            Entitlement::Tours => VirtualTour::where('workspace_id', $workspaceId)->count(),
            Entitlement::ScenesPerTour => $tour?->scenes()->count() ?? 0,
            Entitlement::StorageMb => (int) MediaAsset::where('workspace_id', $workspaceId)->sum('file_size'),
            Entitlement::TeamMembers => DB::table('workspace_members')->where('workspace_id', $workspaceId)->count()
                + WorkspaceInvitation::where('workspace_id', $workspaceId)->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            // Deleted videos still count, so deleting and re-creating cannot bypass the limit.
            Entitlement::VideosPerMonth => VideoProject::withTrashed()->where('workspace_id', $workspaceId)->where('created_at', '>=', now()->startOfMonth())->count(),
            default => 0,
        };
    }

    /**
     * Throw when adding this many would go over the plan's limit.
     */
    public function ensureCanAdd(int $workspaceId, Entitlement $entitlement, int $adding = 1, ?VirtualTour $tour = null): void
    {
        $limit = $this->limit($workspaceId, $entitlement);

        if ($limit === null || $adding <= 0) {
            return;
        }

        if ($entitlement === Entitlement::StorageMb) {
            if ($this->usage($workspaceId, $entitlement) + $adding > $limit * 1024 * 1024) {
                throw PlanLimitReachedException::because(__('Your :plan plan includes :limit of media storage, and it is full. Delete unused media or upgrade your plan.', [
                    'plan' => $this->planName($workspaceId),
                    'limit' => $this->formatMegabytes($limit),
                ]));
            }

            return;
        }

        if ($this->usage($workspaceId, $entitlement, $tour) + $adding > $limit) {
            throw PlanLimitReachedException::because(__('Your :plan plan allows :limit :what. Upgrade your plan to add more.', [
                'plan' => $this->planName($workspaceId),
                'limit' => $limit,
                'what' => mb_strtolower($entitlement->label()),
            ]));
        }
    }

    public function ensureAllowed(int $workspaceId, Entitlement $feature): void
    {
        if (! $this->allows($workspaceId, $feature)) {
            throw PlanLimitReachedException::because(__(':feature is not included in your :plan plan. Upgrade your plan to use it.', [
                'feature' => $feature->label(),
                'plan' => $this->planName($workspaceId),
            ]));
        }
    }

    /**
     * Plan, limits with usage, and features, for billing screens.
     *
     * @return array{plan: array{id: string|null, name: string, price_monthly: int|null, currency: string}, limits: list<array{key: string, label: string, limit: int|null, used: int|null, unit: string|null}>, features: list<array{key: string, label: string, included: bool}>}
     */
    public function summary(int $workspaceId): array
    {
        $plan = $this->plan($workspaceId);

        return [
            'plan' => [
                'id' => $plan?->ulid,
                'name' => $plan->name ?? __('Unlimited'),
                'price_monthly' => $plan?->price_monthly,
                'currency' => $plan->currency ?? (string) config('plans.currency', 'USD'),
            ],
            'limits' => array_map(function (Entitlement $entitlement) use ($workspaceId): array {
                $used = $entitlement->isMetered() && $entitlement !== Entitlement::ScenesPerTour
                    ? $this->usage($workspaceId, $entitlement)
                    : null;

                return [
                    'key' => $entitlement->value,
                    'label' => $entitlement->label(),
                    'limit' => $this->limit($workspaceId, $entitlement),
                    'used' => $entitlement === Entitlement::StorageMb && $used !== null ? (int) ceil($used / 1024 / 1024) : $used,
                    'unit' => $entitlement === Entitlement::StorageMb ? 'MB' : null,
                ];
            }, Entitlement::limits()),
            'features' => array_map(fn (Entitlement $feature): array => [
                'key' => $feature->value,
                'label' => $feature->label(),
                'included' => $this->allows($workspaceId, $feature),
            ], Entitlement::features()),
        ];
    }

    protected function planName(int $workspaceId): string
    {
        return $this->plan($workspaceId)->name ?? __('current');
    }

    protected function formatMegabytes(int $megabytes): string
    {
        return $megabytes >= 1000 ? rtrim(rtrim(number_format($megabytes / 1000, 1), '0'), '.').' GB' : $megabytes.' MB';
    }
}
