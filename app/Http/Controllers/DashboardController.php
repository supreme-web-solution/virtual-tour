<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Enums\TourStatus;
use App\Models\ActivityLog;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\TourLead;
use App\Models\TourScene;
use App\Models\TourVisit;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\Billing\EntitlementService;
use App\Services\Credits\CreditService;
use App\Services\Tours\TourAnalyticsService;
use App\Services\Tours\TourPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Days covered by the headline numbers, compared with the days before.
     */
    public const PERIOD_DAYS = 30;

    public function __construct(protected TourPresenter $tours) {}

    /**
     * Workspace overview with real counts and recent activity.
     */
    public function __invoke(Request $request, TourAnalyticsService $analytics, EntitlementService $entitlements, CreditService $credits): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = $user->current_workspace_id;

        $mediaByType = MediaAsset::query()
            ->where('workspace_id', $workspaceId)
            ->select('asset_type', DB::raw('count(*) as total'))
            ->groupBy('asset_type')
            ->pluck('total', 'asset_type');

        $workspaceId = (int) $workspaceId;

        return Inertia::render('Dashboard', [
            'insights' => $this->insights($analytics, $workspaceId),
            'topTours' => $this->topTours($workspaceId),
            'recentLeads' => TourLead::where('workspace_id', $workspaceId)
                ->with('tour:id,ulid,title')
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (TourLead $lead): array => [
                    'id' => $lead->ulid,
                    'name' => $lead->name,
                    'email' => $lead->email,
                    'tour' => $lead->tour?->title,
                    'is_read' => $lead->read_at !== null,
                    'created_at' => $lead->created_at?->toIso8601String(),
                ])
                ->values(),
            'unreadLeads' => TourLead::where('workspace_id', $workspaceId)->whereNull('read_at')->count(),
            'plan' => [
                'name' => $entitlements->summary($workspaceId)['plan']['name'],
                'credits' => $credits->available($workspaceId),
            ],
            'building' => VirtualTour::query()
                ->where('workspace_id', $workspaceId)
                ->whereNotNull('build_state')
                ->latest('updated_at')
                ->limit(5)
                ->get()
                ->filter(fn (VirtualTour $tour): bool => in_array($tour->build_state['stage'] ?? null, ['planning', 'analysing', 'importing', 'generating', 'failed'], true))
                ->map(fn (VirtualTour $tour): array => ['id' => $tour->ulid, 'title' => $tour->title, 'stage' => $tour->build_state['stage']])
                ->values(),
            'stats' => [
                'properties' => Property::where('workspace_id', $workspaceId)->count(),
                'tours' => VirtualTour::where('workspace_id', $workspaceId)->count(),
                'published_tours' => VirtualTour::where('workspace_id', $workspaceId)->where('status', TourStatus::Published)->count(),
                'scenes' => TourScene::whereHas('tour', fn ($query) => $query->where('workspace_id', $workspaceId))->count(),
                'media' => (int) $mediaByType->sum(),
                'photos' => (int) ($mediaByType[AssetType::Photo->value] ?? 0),
                'panoramas' => (int) ($mediaByType[AssetType::Panorama->value] ?? 0),
            ],
            'recentTours' => VirtualTour::query()
                ->where('workspace_id', $workspaceId)
                ->with(['property', 'coverAsset', 'scenes' => fn ($query) => $query->with('mediaAsset')->limit(1)])
                ->withCount('scenes')
                ->latest('updated_at')
                ->limit(6)
                ->get()
                ->map(fn (VirtualTour $tour): array => $this->tours->listItem($tour, $user))
                ->values(),
            'activity' => ActivityLog::query()
                ->where('workspace_id', $workspaceId)
                ->with('user:id,name')
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->map(fn (ActivityLog $log): array => [
                    'id' => $log->id,
                    'description' => $log->description,
                    'user' => $log->user?->name,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
            'can' => [
                'createTour' => $user->can('create', VirtualTour::class),
                'createProperty' => $user->can('create', Property::class),
                'uploadMedia' => $user->can('create', MediaAsset::class),
            ],
        ]);
    }

    /**
     * Headline numbers for the last 30 days, each with the change against the
     * 30 days before, and a daily series for the chart.
     *
     * @return array{days: int, current: array<string, int|float>, previous: array<string, int|float>, daily: list<array{date: string, total: int}>}
     */
    protected function insights(TourAnalyticsService $analytics, int $workspaceId): array
    {
        $days = self::PERIOD_DAYS;
        $to = CarbonImmutable::now();
        $from = $to->subDays($days - 1)->startOfDay();
        $previousTo = $from->subSecond();
        $previousFrom = $from->subDays($days);

        $current = $analytics->report($workspaceId, null, $from, $to);
        $previous = $analytics->report($workspaceId, null, $previousFrom, $previousTo);

        $pick = fn (array $report): array => [
            'views' => $report['views'],
            'unique_visitors' => $report['unique_visitors'],
            'average_duration_seconds' => $report['average_duration_seconds'],
            'completion_rate' => $report['completion_rate'],
            'leads' => $report['leads'],
            'hotspot_clicks' => $report['hotspot_clicks'],
        ];

        return [
            'days' => $days,
            'current' => $pick($current),
            'previous' => $pick($previous),
            'daily' => $current['daily'],
        ];
    }

    /**
     * The tours with the most visits in the period.
     *
     * @return list<array{id: string, title: string, views: int}>
     */
    protected function topTours(int $workspaceId): array
    {
        $from = CarbonImmutable::now()->subDays(self::PERIOD_DAYS - 1)->startOfDay();

        $totals = TourVisit::query()
            ->where('workspace_id', $workspaceId)
            ->where('started_at', '>=', $from)
            ->select('virtual_tour_id', DB::raw('count(*) as total'))
            ->groupBy('virtual_tour_id')
            ->orderByDesc('total')
            ->limit(5)
            ->toBase()
            ->get();

        $tours = VirtualTour::whereIn('id', $totals->pluck('virtual_tour_id'))->get(['id', 'ulid', 'title'])->keyBy('id');

        return array_values($totals
            ->filter(fn (object $row): bool => $tours->has(data_get($row, 'virtual_tour_id')))
            ->map(fn (object $row): array => [
                'id' => $tours[data_get($row, 'virtual_tour_id')]->ulid,
                'title' => $tours[data_get($row, 'virtual_tour_id')]->title,
                'views' => (int) data_get($row, 'total'),
            ])
            ->values()
            ->all());
    }
}
