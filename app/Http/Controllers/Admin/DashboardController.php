<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\TourStatus;
use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VirtualTour;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform overview: totals, provider usage and credit flow (last 30 days).
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $since = now()->subDays(30);

        $usage = AiGeneration::query()
            ->where('created_at', '>=', $since)
            ->select('provider', 'generation_type')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as completed', [GenerationStatus::Completed->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [GenerationStatus::Failed->value])
            ->selectRaw('coalesce(sum(credits_charged), 0) as credits')
            ->selectRaw('coalesce(sum(actual_cost), 0) as cost')
            ->groupBy('provider', 'generation_type')
            ->orderByDesc('total')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'provider' => $row->provider,
                'type' => GenerationType::tryFrom($row->generation_type)?->label() ?? $row->generation_type,
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'failed' => (int) $row->failed,
                'credits' => (int) $row->credits,
                'cost' => round((float) $row->cost, 2),
            ])->values();

        $credits = CreditTransaction::where('created_at', '>=', $since)
            ->select('transaction_type')
            ->selectRaw('coalesce(sum(amount), 0) as total')
            ->groupBy('transaction_type')
            ->toBase()
            ->pluck('total', 'transaction_type');

        $plans = Plan::orderBy('sort_order')->get(['id', 'name'])->map(fn (Plan $plan): array => [
            'name' => $plan->name,
            'workspaces' => Subscription::current()->where('plan_id', $plan->id)->distinct()->count('workspace_id'),
        ])->values();

        return Inertia::render('admin/Dashboard', [
            'totals' => [
                'users' => User::count(),
                'workspaces' => Workspace::count(),
                'properties' => Property::count(),
                'tours' => VirtualTour::count(),
                'published_tours' => VirtualTour::where('status', TourStatus::Published)->count(),
                'videos' => VideoProject::count(),
                'active_generations' => AiGeneration::whereIn('status', GenerationStatus::activeValues())->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'new_users' => User::where('created_at', '>=', $since)->count(),
            ],
            'usage' => $usage,
            'credits' => [
                'granted' => (int) ($credits['grant'] ?? 0),
                'charged' => abs((int) ($credits['charge'] ?? 0)),
            ],
            'plans' => $plans,
        ]);
    }
}
