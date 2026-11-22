<?php

namespace App\Services\Tours;

use App\Models\TourEvent;
use App\Models\TourHotspot;
use App\Models\TourLead;
use App\Models\TourScene;
use App\Models\TourVisit;
use App\Models\VirtualTour;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Privacy-conscious visitor analytics.
 *
 * No IP addresses, user agents or raw browser identifiers are stored: a
 * random ID generated in the visitor's browser is reduced to a keyed hash,
 * so "unique visitors" is an estimate (it resets when storage is cleared).
 */
class TourAnalyticsService
{
    /**
     * @param  array{visitor_id: string, referrer?: string|null, embed?: bool}  $input
     */
    public function startVisit(VirtualTour $tour, array $input, ?string $userAgent, ?string $country, string $ownHost): TourVisit
    {
        $now = now();

        return TourVisit::create([
            'virtual_tour_id' => $tour->id,
            'workspace_id' => $tour->workspace_id,
            'visitor_hash' => hash_hmac('sha256', $input['visitor_id'], (string) config('app.key')),
            'device_type' => $this->deviceType($userAgent),
            'country' => $country !== null && preg_match('/^[A-Z]{2}$/', $country) ? $country : null,
            'referrer_host' => $this->referrerHost($input['referrer'] ?? null, $ownHost),
            'is_embed' => (bool) ($input['embed'] ?? false),
            'scene_count' => $tour->scenes()->where('is_visible', true)->count(),
            'started_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * Record a batch of events for a visit. Unknown scene/hotspot IDs are ignored.
     *
     * @param  list<array{type: string, scene_id?: string|null, hotspot_id?: string|null}>  $events
     */
    public function record(TourVisit $visit, array $events): void
    {
        $sceneIds = TourScene::where('virtual_tour_id', $visit->virtual_tour_id)->pluck('id', 'ulid');
        $hotspotIds = TourHotspot::whereIn('tour_scene_id', $sceneIds->values())->pluck('id', 'ulid');
        $now = now();

        $rows = [];

        foreach ($events as $event) {
            if (! in_array($event['type'], TourEvent::TYPES, true)) {
                continue;
            }

            $rows[] = [
                'tour_visit_id' => $visit->id,
                'virtual_tour_id' => $visit->virtual_tour_id,
                'type' => $event['type'],
                'tour_scene_id' => $sceneIds[$event['scene_id'] ?? ''] ?? null,
                'tour_hotspot_id' => $hotspotIds[$event['hotspot_id'] ?? ''] ?? null,
                'created_at' => $now,
            ];
        }

        DB::transaction(function () use ($visit, $rows, $now): void {
            if ($rows !== []) {
                TourEvent::insert($rows);
            }

            $visit->forceFill([
                'last_seen_at' => $now,
                'scenes_viewed' => TourEvent::where('tour_visit_id', $visit->id)
                    ->where('type', 'scene_view')
                    ->whereNotNull('tour_scene_id')
                    ->distinct()
                    ->count('tour_scene_id'),
            ])->save();
        });
    }

    /**
     * Aggregated report for one tour or every tour in a workspace.
     *
     * @return array<string, mixed>
     */
    public function report(int $workspaceId, ?int $tourId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $visits = TourVisit::query()
            ->where('workspace_id', $workspaceId)
            ->when($tourId, fn ($query) => $query->where('virtual_tour_id', $tourId))
            ->whereBetween('started_at', [$from, $to]);

        $total = (clone $visits)->count();
        $durations = (clone $visits)->get(['started_at', 'last_seen_at'])
            ->map(fn (TourVisit $visit): int => max(0, (int) $visit->started_at->diffInSeconds($visit->last_seen_at)));

        $events = TourEvent::query()
            ->join('tour_visits', 'tour_visits.id', '=', 'tour_events.tour_visit_id')
            ->where('tour_visits.workspace_id', $workspaceId)
            ->when($tourId, fn ($query) => $query->where('tour_events.virtual_tour_id', $tourId))
            ->whereBetween('tour_events.created_at', [$from, $to]);

        return [
            'views' => $total,
            'unique_visitors' => (clone $visits)->distinct()->count('visitor_hash'),
            'average_duration_seconds' => $durations->isEmpty() ? 0 : (int) round($durations->avg()),
            'completion_rate' => $total === 0 ? 0 : round((clone $visits)->where('scene_count', '>', 0)->whereColumn('scenes_viewed', '>=', 'scene_count')->count() / $total * 100, 1),
            'hotspot_clicks' => (clone $events)->whereIn('tour_events.type', ['hotspot_click', 'link_click'])->count(),
            'leads' => TourLead::where('workspace_id', $workspaceId)
                ->when($tourId, fn ($query) => $query->where('virtual_tour_id', $tourId))
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            'embed_views' => (clone $visits)->where('is_embed', true)->count(),
            'daily' => $this->daily(clone $visits, $from, $to),
            'devices' => $this->breakdown(clone $visits, 'device_type'),
            'countries' => $this->breakdown(clone $visits, 'country'),
            'referrers' => $this->breakdown(clone $visits, 'referrer_host'),
            'top_scenes' => (clone $events)
                ->where('tour_events.type', 'scene_view')
                ->join('tour_scenes', 'tour_scenes.id', '=', 'tour_events.tour_scene_id')
                ->select('tour_scenes.title', DB::raw('count(*) as total'))
                ->groupBy('tour_scenes.id', 'tour_scenes.title')
                ->orderByDesc('total')
                ->limit(10)
                ->toBase()
                ->get()
                ->map(fn (object $row): array => ['label' => (string) data_get($row, 'title'), 'total' => (int) data_get($row, 'total')])
                ->values()
                ->all(),
        ];
    }

    /**
     * Remove visits (and their events) older than the retention period.
     */
    public function prune(int $retentionDays): int
    {
        return TourVisit::where('started_at', '<', now()->subDays($retentionDays))->delete();
    }

    /**
     * @param  Builder<TourVisit>  $visits
     * @return list<array{date: string, total: int}>
     */
    protected function daily($visits, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = $visits->get(['started_at'])
            ->groupBy(fn (TourVisit $visit): string => $visit->started_at->toDateString())
            ->map(fn (Collection $group): int => $group->count());

        $days = [];

        for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            $days[] = ['date' => $day->toDateString(), 'total' => (int) ($counts[$day->toDateString()] ?? 0)];
        }

        return $days;
    }

    /**
     * @param  Builder<TourVisit>  $visits
     * @return list<array{label: string, total: int}>
     */
    protected function breakdown($visits, string $column): array
    {
        return array_values($visits->select($column, DB::raw('count(*) as total'))
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit(8)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['label' => (string) (data_get($row, $column) ?? 'Unknown'), 'total' => (int) data_get($row, 'total')])
            ->all());
    }

    protected function deviceType(?string $userAgent): string
    {
        $agent = strtolower((string) $userAgent);

        return match (true) {
            str_contains($agent, 'ipad') || str_contains($agent, 'tablet') || (str_contains($agent, 'android') && ! str_contains($agent, 'mobile')) => 'tablet',
            str_contains($agent, 'mobi') || str_contains($agent, 'iphone') || str_contains($agent, 'android') => 'mobile',
            default => 'desktop',
        };
    }

    protected function referrerHost(?string $referrer, string $ownHost): ?string
    {
        $host = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '' || strcasecmp($host, $ownHost) === 0) {
            return null;
        }

        return mb_substr(strtolower($host), 0, 255);
    }
}
