<?php

namespace App\Services\Tours;

use App\Models\TourEvent;
use App\Models\TourLead;
use App\Models\TourScene;
use App\Models\TourVisit;
use App\Models\VirtualTour;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deeper tour analytics for plans that include them: period comparison,
 * per-tour performance, scene funnel, hotspot performance and a weekday ×
 * hour heatmap. Everything is aggregated; no visitor is identifiable.
 */
class AdvancedAnalyticsService
{
    /**
     * @return array{comparison: array<string, array{current: int|float, previous: int|float, change: float|null}>, tours: list<array<string, mixed>>, funnel: list<array{label: string, visits: int, percent: float}>|null, hotspots: list<array{label: string, scene: string|null, type: string, clicks: int}>, heatmap: list<list<int>>, timezone: string}
     */
    public function report(int $workspaceId, ?VirtualTour $tour, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $length = $from->diffInSeconds($to);
        $previousFrom = $from->subSeconds((int) $length + 1);
        $previousTo = $from->subSecond();

        return [
            'comparison' => $this->comparison(
                $this->totals($workspaceId, $tour?->id, $from, $to),
                $this->totals($workspaceId, $tour?->id, $previousFrom, $previousTo),
            ),
            'tours' => $tour === null ? $this->tours($workspaceId, $from, $to) : [],
            'funnel' => $tour === null ? null : $this->funnel($tour, $from, $to),
            'hotspots' => $this->hotspots($workspaceId, $tour?->id, $from, $to),
            'heatmap' => $this->heatmap($workspaceId, $tour?->id, $from, $to),
            'timezone' => (string) config('app.timezone'),
        ];
    }

    /**
     * Daily totals per tour, for CSV export.
     *
     * @return list<array{date: string, tour: string, views: int, unique_visitors: int, average_duration_seconds: int, completed: int, leads: int}>
     */
    public function dailyRows(int $workspaceId, ?int $tourId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $day = $this->dateExpression('tour_visits.started_at');

        $rows = $this->visits($workspaceId, $tourId, $from, $to)
            ->join('virtual_tours', 'virtual_tours.id', '=', 'tour_visits.virtual_tour_id')
            ->selectRaw("{$day} as day, virtual_tours.id as tour_id, virtual_tours.title as tour_title")
            ->selectRaw('count(*) as views, count(distinct visitor_hash) as unique_visitors')
            ->selectRaw('avg('.$this->durationExpression().') as average_duration')
            ->selectRaw('sum(case when scene_count > 0 and scenes_viewed >= scene_count then 1 else 0 end) as completed')
            ->groupByRaw("{$day}, virtual_tours.id, virtual_tours.title")
            ->orderBy('day')
            ->toBase()
            ->get();

        $leadDay = $this->dateExpression('created_at');
        $leads = TourLead::where('workspace_id', $workspaceId)
            ->when($tourId, fn ($query) => $query->where('virtual_tour_id', $tourId))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("{$leadDay} as day, virtual_tour_id, count(*) as total")
            ->groupByRaw("{$leadDay}, virtual_tour_id")
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [data_get($row, 'day').'|'.data_get($row, 'virtual_tour_id') => (int) data_get($row, 'total')]);

        return array_values($rows->map(fn (object $row): array => [
            'date' => (string) data_get($row, 'day'),
            'tour' => (string) data_get($row, 'tour_title'),
            'views' => (int) data_get($row, 'views'),
            'unique_visitors' => (int) data_get($row, 'unique_visitors'),
            'average_duration_seconds' => (int) round((float) data_get($row, 'average_duration')),
            'completed' => (int) data_get($row, 'completed'),
            'leads' => $leads[data_get($row, 'day').'|'.data_get($row, 'tour_id')] ?? 0,
        ])->all());
    }

    /**
     * @return array{views: int, unique_visitors: int, average_duration_seconds: int, leads: int}
     */
    protected function totals(int $workspaceId, ?int $tourId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->visits($workspaceId, $tourId, $from, $to)
            ->selectRaw('count(*) as views, count(distinct visitor_hash) as unique_visitors, avg('.$this->durationExpression().') as average_duration')
            ->toBase()
            ->first();

        return [
            'views' => (int) data_get($row, 'views'),
            'unique_visitors' => (int) data_get($row, 'unique_visitors'),
            'average_duration_seconds' => (int) round((float) data_get($row, 'average_duration')),
            'leads' => TourLead::where('workspace_id', $workspaceId)
                ->when($tourId, fn ($query) => $query->where('virtual_tour_id', $tourId))
                ->whereBetween('created_at', [$from, $to])
                ->count(),
        ];
    }

    /**
     * @param  array<string, int>  $current
     * @param  array<string, int>  $previous
     * @return array<string, array{current: int|float, previous: int|float, change: float|null}>
     */
    protected function comparison(array $current, array $previous): array
    {
        $current['conversion_rate'] = $current['views'] === 0 ? 0 : round($current['leads'] / $current['views'] * 100, 1);
        $previous['conversion_rate'] = $previous['views'] === 0 ? 0 : round($previous['leads'] / $previous['views'] * 100, 1);

        $result = [];

        foreach ($current as $key => $value) {
            $before = $previous[$key];
            $result[$key] = [
                'current' => $value,
                'previous' => $before,
                // Percentage change; null when there is nothing to compare with.
                'change' => $before == 0 ? null : round(($value - $before) / $before * 100, 1),
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function tours(int $workspaceId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $stats = $this->visits($workspaceId, null, $from, $to)
            ->select('virtual_tour_id')
            ->selectRaw('count(*) as views, count(distinct visitor_hash) as unique_visitors')
            ->selectRaw('avg('.$this->durationExpression().') as average_duration')
            ->selectRaw('sum(case when scene_count > 0 and scenes_viewed >= scene_count then 1 else 0 end) as completed')
            ->groupBy('virtual_tour_id')
            ->toBase()
            ->get()
            ->keyBy('virtual_tour_id');

        $leads = TourLead::where('workspace_id', $workspaceId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('virtual_tour_id, count(*) as total')
            ->groupBy('virtual_tour_id')
            ->toBase()
            ->pluck('total', 'virtual_tour_id');

        $ids = $stats->keys()->merge($leads->keys())->unique()->all();

        return array_values(VirtualTour::withTrashed()->whereIn('id', $ids)->get(['id', 'ulid', 'title'])
            ->map(function (VirtualTour $tour) use ($stats, $leads): array {
                $row = $stats->get($tour->id);
                $views = (int) data_get($row, 'views');
                $leadCount = (int) ($leads[$tour->id] ?? 0);

                return [
                    'id' => $tour->ulid,
                    'title' => $tour->title,
                    'views' => $views,
                    'unique_visitors' => (int) data_get($row, 'unique_visitors'),
                    'average_duration_seconds' => (int) round((float) data_get($row, 'average_duration')),
                    'completion_rate' => $views === 0 ? 0 : round((int) data_get($row, 'completed') / $views * 100, 1),
                    'leads' => $leadCount,
                    'conversion_rate' => $views === 0 ? 0 : round($leadCount / $views * 100, 1),
                ];
            })
            ->sortByDesc('views')
            ->values()
            ->all());
    }

    /**
     * Share of the tour's visits that reached each scene, in tour order.
     *
     * @return list<array{label: string, visits: int, percent: float}>
     */
    protected function funnel(VirtualTour $tour, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $total = $this->visits($tour->workspace_id, $tour->id, $from, $to)->count();

        $reached = TourEvent::query()
            ->join('tour_visits', 'tour_visits.id', '=', 'tour_events.tour_visit_id')
            ->where('tour_events.virtual_tour_id', $tour->id)
            ->where('tour_events.type', 'scene_view')
            ->whereBetween('tour_visits.started_at', [$from, $to])
            ->selectRaw('tour_events.tour_scene_id, count(distinct tour_events.tour_visit_id) as visits')
            ->groupBy('tour_events.tour_scene_id')
            ->toBase()
            ->pluck('visits', 'tour_scene_id');

        return array_values($tour->scenes()->orderBy('sort_order')->get(['id', 'title'])
            ->map(fn (TourScene $scene): array => [
                'label' => $scene->title,
                'visits' => (int) ($reached[$scene->id] ?? 0),
                'percent' => $total === 0 ? 0 : round((int) ($reached[$scene->id] ?? 0) / $total * 100, 1),
            ])
            ->all());
    }

    /**
     * @return list<array{label: string, scene: string|null, type: string, clicks: int}>
     */
    protected function hotspots(int $workspaceId, ?int $tourId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(TourEvent::query()
            ->join('tour_visits', 'tour_visits.id', '=', 'tour_events.tour_visit_id')
            ->join('tour_hotspots', 'tour_hotspots.id', '=', 'tour_events.tour_hotspot_id')
            ->leftJoin('tour_scenes', 'tour_scenes.id', '=', 'tour_hotspots.tour_scene_id')
            ->where('tour_visits.workspace_id', $workspaceId)
            ->when($tourId, fn ($query) => $query->where('tour_events.virtual_tour_id', $tourId))
            ->whereIn('tour_events.type', ['hotspot_click', 'link_click'])
            ->whereBetween('tour_events.created_at', [$from, $to])
            ->select('tour_hotspots.id', 'tour_hotspots.title', 'tour_hotspots.type', 'tour_scenes.title as scene_title')
            ->selectRaw('count(*) as clicks')
            ->groupBy('tour_hotspots.id', 'tour_hotspots.title', 'tour_hotspots.type', 'tour_scenes.title')
            ->orderByDesc('clicks')
            ->limit(10)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'label' => (string) (data_get($row, 'title') ?: ucfirst(str_replace('_', ' ', (string) data_get($row, 'type'))).' hotspot'),
                'scene' => data_get($row, 'scene_title'),
                'type' => (string) data_get($row, 'type'),
                'clicks' => (int) data_get($row, 'clicks'),
            ])
            ->all());
    }

    /**
     * Visits by weekday (Monday first) and hour, in the app's timezone.
     *
     * @return list<list<int>>
     */
    protected function heatmap(int $workspaceId, ?int $tourId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));

        $this->visits($workspaceId, $tourId, $from, $to)
            ->select('started_at')
            ->orderBy('id')
            ->lazy(2000)
            ->each(function (TourVisit $visit) use (&$grid): void {
                $grid[$visit->started_at->dayOfWeekIso - 1][$visit->started_at->hour]++;
            });

        return array_values(array_map(fn (array $row): array => array_values($row), $grid));
    }

    /**
     * @return Builder<TourVisit>
     */
    protected function visits(int $workspaceId, ?int $tourId, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return TourVisit::query()
            ->where('tour_visits.workspace_id', $workspaceId)
            ->when($tourId, fn ($query) => $query->where('tour_visits.virtual_tour_id', $tourId))
            ->whereBetween('tour_visits.started_at', [$from, $to]);
    }

    /**
     * Visit length in seconds, as SQL for the current database.
     *
     * @return literal-string
     */
    protected function durationExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? '((julianday(tour_visits.last_seen_at) - julianday(tour_visits.started_at)) * 86400)'
            : 'timestampdiff(second, tour_visits.started_at, tour_visits.last_seen_at)';
    }

    /**
     * Calendar day of a timestamp column (same syntax on MySQL and SQLite).
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    protected function dateExpression(string $column): string
    {
        return "date({$column})";
    }
}
