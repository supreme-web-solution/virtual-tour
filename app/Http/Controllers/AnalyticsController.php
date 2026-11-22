<?php

namespace App\Http\Controllers;

use App\Enums\Entitlement;
use App\Models\TourLead;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VirtualTour;
use App\Services\ActivityLogger;
use App\Services\Billing\EntitlementService;
use App\Services\Tours\AdvancedAnalyticsService;
use App\Services\Tours\TourAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    /**
     * Date ranges in days. 180 and 365 are part of advanced analytics.
     */
    public const RANGES = ['7', '30', '90', '180', '365'];

    public function index(Request $request, TourAnalyticsService $analytics, AdvancedAnalyticsService $advanced, EntitlementService $entitlements): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->current_workspace_id;
        $role = $user->roleIn($workspaceId);
        abort_if($role === null, 403);

        $filters = $request->validate([
            'tour' => ['nullable', 'string', Rule::exists(VirtualTour::class, 'ulid')->where('workspace_id', $workspaceId)],
            'range' => ['nullable', Rule::in(self::RANGES)],
            'tab' => ['nullable', Rule::in(['overview', 'leads', 'videos'])],
        ]);

        $hasAdvanced = $entitlements->allows($workspaceId, Entitlement::AdvancedAnalytics);
        $days = min((int) ($filters['range'] ?? 30), $hasAdvanced ? 365 : 90);
        $tab = $filters['tab'] ?? 'overview';
        $tour = filled($filters['tour'] ?? null) ? VirtualTour::where('ulid', $filters['tour'])->first() : null;
        $to = CarbonImmutable::now();
        $from = $to->subDays($days - 1)->startOfDay();

        $leads = TourLead::where('workspace_id', $workspaceId)
            ->when($tour, fn ($query) => $query->where('virtual_tour_id', $tour->id))
            ->with('tour:id,ulid,title')
            ->latest()
            ->paginate(20, pageName: 'leads_page')
            ->withQueryString()
            ->through(fn (TourLead $lead): array => [
                'id' => $lead->ulid,
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'message' => $lead->message,
                'tour' => $lead->tour?->title,
                'is_read' => $lead->read_at !== null,
                'created_at' => $lead->created_at?->toIso8601String(),
            ]);

        return Inertia::render('analytics/Index', [
            'report' => $analytics->report($workspaceId, $tour?->id, $from, $to),
            'advanced' => $hasAdvanced && $tab === 'overview' ? $advanced->report($workspaceId, $tour, $from, $to) : null,
            'videos' => $tab === 'videos' ? $this->videos($workspaceId) : null,
            'features' => ['advanced' => $hasAdvanced],
            'leads' => $leads,
            'unreadLeads' => TourLead::where('workspace_id', $workspaceId)->whereNull('read_at')->count(),
            'tours' => VirtualTour::where('workspace_id', $workspaceId)->orderBy('title')->get(['ulid', 'title'])
                ->map(fn (VirtualTour $item): array => ['id' => $item->ulid, 'title' => $item->title])->values(),
            'filters' => [
                'tour' => $tour->ulid ?? '',
                'range' => (string) $days,
                'tab' => $tab,
            ],
            'retentionDays' => (int) config('analytics.retention_days'),
            'can' => [
                'manageLeads' => $role->canEdit(),
                'deleteLeads' => $role->canManage(),
                'exportLeads' => $role->canManage(),
            ],
        ]);
    }

    /**
     * CSV download of daily tour totals, or of enquiries (owners and admins).
     */
    public function export(Request $request, AdvancedAnalyticsService $advanced, EntitlementService $entitlements): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->current_workspace_id;
        $role = $user->roleIn($workspaceId);
        abort_if($role === null, 403);

        $filters = $request->validate([
            'type' => ['required', Rule::in(['visits', 'leads', 'contacts'])],
            'tour' => ['nullable', 'string', Rule::exists(VirtualTour::class, 'ulid')->where('workspace_id', $workspaceId)],
            'range' => ['nullable', Rule::in(self::RANGES)],
        ]);

        $tour = filled($filters['tour'] ?? null) ? VirtualTour::where('ulid', $filters['tour'])->first() : null;
        $to = CarbonImmutable::now();
        $from = $to->subDays((int) ($filters['range'] ?? 30) - 1)->startOfDay();
        $filename = 'tour-'.$filters['type'].'-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        if ($filters['type'] === 'contacts') {
            // Just the people: one row per email address, newest name wins.
            abort_unless($role->canManage(), 403);

            $contacts = TourLead::where('workspace_id', $workspaceId)
                ->when($tour, fn ($query) => $query->where('virtual_tour_id', $tour->id))
                ->whereBetween('created_at', [$from, $to])
                ->orderByDesc('id');

            return $this->csv($filename, ['Name', 'Email'], function () use ($contacts): iterable {
                $seen = [];

                foreach ($contacts->lazy(500) as $lead) {
                    $key = mb_strtolower($lead->email);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;

                    yield [$lead->name, $lead->email];
                }
            });
        }

        if ($filters['type'] === 'leads') {
            // Enquiries contain personal data: only people who may delete them can export them.
            abort_unless($role->canManage(), 403);

            $leads = TourLead::where('workspace_id', $workspaceId)
                ->when($tour, fn ($query) => $query->where('virtual_tour_id', $tour->id))
                ->whereBetween('created_at', [$from, $to])
                ->with('tour:id,title')
                ->orderBy('id');

            return $this->csv($filename, ['Received', 'Tour', 'Name', 'Email', 'Phone', 'Message'], function () use ($leads): iterable {
                foreach ($leads->lazy(500) as $lead) {
                    yield [$lead->created_at?->toDateTimeString(), $lead->tour?->title, $lead->name, $lead->email, $lead->phone, $lead->message];
                }
            });
        }

        $entitlements->ensureAllowed($workspaceId, Entitlement::AdvancedAnalytics);

        $rows = $advanced->dailyRows($workspaceId, $tour?->id, $from, $to);

        return $this->csv(
            $filename,
            ['Date', 'Tour', 'Views', 'Unique visitors', 'Average time (s)', 'Completed visits', 'Enquiries'],
            fn (): iterable => array_map(fn (array $row): array => array_values($row), $rows),
        );
    }

    public function markLead(Request $request, TourLead $lead): RedirectResponse
    {
        abort_unless((bool) $request->user()?->roleIn($lead->workspace_id)?->canEdit(), 403);

        $lead->update(['read_at' => $request->boolean('read') ? now() : null]);

        return back();
    }

    public function destroyLead(Request $request, TourLead $lead, ActivityLogger $activity): RedirectResponse
    {
        abort_unless((bool) $request->user()?->roleIn($lead->workspace_id)?->canManage(), 403);

        $lead->delete();
        $activity->log($lead->workspace_id, $request->user(), 'lead.deleted', 'Deleted an enquiry from '.$lead->tour?->title);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Enquiry deleted.')]);

        return back();
    }

    /**
     * @param  list<string>  $header
     * @param  callable(): iterable<array<int, mixed>>  $rows
     */
    protected function csv(string $filename, array $header, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, $header);

            foreach ($rows() as $row) {
                // Stop spreadsheet apps treating visitor-supplied text as a formula.
                fputcsv($out, array_map(
                    fn (mixed $value): mixed => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value,
                    $row,
                ));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Rendered-video metrics for the Videos tab.
     *
     * @return array{totals: array<string, int>, items: list<array<string, mixed>>}
     */
    protected function videos(int $workspaceId): array
    {
        $items = VideoProject::where('workspace_id', $workspaceId)
            ->whereNotNull('final_asset_id')
            ->latest('id')
            ->limit(100)
            ->get(['id', 'ulid', 'title', 'published_at', 'visibility', 'views_count', 'plays_count', 'completions_count', 'downloads_count', 'embed_views_count'])
            ->map(fn (VideoProject $video): array => [
                'id' => $video->ulid,
                'title' => $video->title,
                'published' => $video->published_at !== null,
                'views' => $video->views_count,
                'embed_views' => $video->embed_views_count,
                'plays' => $video->plays_count,
                'completions' => $video->completions_count,
                'downloads' => $video->downloads_count,
                'completion_rate' => $video->plays_count === 0 ? null : round($video->completions_count / $video->plays_count * 100, 1),
            ]);

        return [
            'totals' => [
                'views' => (int) $items->sum('views'),
                'embed_views' => (int) $items->sum('embed_views'),
                'plays' => (int) $items->sum('plays'),
                'completions' => (int) $items->sum('completions'),
                'downloads' => (int) $items->sum('downloads'),
            ],
            'items' => array_values($items->all()),
        ];
    }
}
