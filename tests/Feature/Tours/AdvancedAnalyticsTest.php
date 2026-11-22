<?php

use App\Enums\WorkspaceRole;
use App\Models\MediaAsset;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TourEvent;
use App\Models\TourHotspot;
use App\Models\TourLead;
use App\Models\TourScene;
use App\Models\TourVisit;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VirtualTour;
use App\Services\Billing\EntitlementService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 18)->setTime(12, 0));

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'Seaside Villa']);
    $this->lounge = TourScene::factory()->forTour($this->tour)->create(['title' => 'Lounge', 'sort_order' => 1]);
    $this->pool = TourScene::factory()->forTour($this->tour)->create(['title' => 'Pool', 'sort_order' => 2]);
});

function analyticsPlan(bool $advanced): void
{
    $plan = Plan::factory()->with(['advanced_analytics' => $advanced])->create();
    Subscription::factory()->create(['workspace_id' => test()->workspace->id, 'plan_id' => $plan->id]);
    app(EntitlementService::class)->forget();
}

/**
 * A visit that viewed the given scenes, started at the given time.
 *
 * @param  list<TourScene>  $scenes
 */
function recordVisit(VirtualTour $tour, array $scenes, string $startedAt, int $seconds = 60, string $visitor = 'v1'): TourVisit
{
    $start = now()->parse($startedAt);
    $visit = TourVisit::create([
        'virtual_tour_id' => $tour->id,
        'workspace_id' => $tour->workspace_id,
        'visitor_hash' => hash('sha256', $visitor),
        'device_type' => 'desktop',
        'scene_count' => 2,
        'scenes_viewed' => count($scenes),
        'started_at' => $start,
        'last_seen_at' => $start->addSeconds($seconds),
    ]);

    foreach ($scenes as $scene) {
        TourEvent::create(['tour_visit_id' => $visit->id, 'virtual_tour_id' => $tour->id, 'type' => 'scene_view', 'tour_scene_id' => $scene->id, 'created_at' => $start]);
    }

    return $visit;
}

function recordLead(VirtualTour $tour, string $at, array $attributes = []): TourLead
{
    $lead = new TourLead(['name' => 'Ada', 'email' => 'ada@example.com', ...$attributes]);
    $lead->workspace_id = $tour->workspace_id;
    $lead->virtual_tour_id = $tour->id;
    $lead->created_at = now()->parse($at);
    $lead->save();

    return $lead;
}

test('without advanced analytics the extras are hidden, ranges stop at 90 days and export is refused', function () {
    analyticsPlan(false);

    $this->actingAs($this->owner)
        ->get(route('analytics.index', ['range' => 365]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('advanced', null)
            ->where('features.advanced', false)
            ->where('filters.range', '90')
            ->where('can.exportLeads', true));

    $this->get(route('analytics.export', ['type' => 'visits']))->assertSessionHasErrors('plan');
    $this->get(route('analytics.export', ['type' => 'leads']))->assertOk();
});

test('advanced analytics compares periods and ranks tours by conversion', function () {
    analyticsPlan(true);
    $other = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'City Loft']);

    // Current 7 days: 4 villa visits (1 lead), 1 loft visit. Previous 7 days: 2 villa visits.
    recordVisit($this->tour, [$this->lounge, $this->pool], '2026-11-17 09:15', 120, 'a');
    recordVisit($this->tour, [$this->lounge], '2026-11-17 09:40', 30, 'b');
    recordVisit($this->tour, [$this->lounge], '2026-11-16 21:05', 30, 'a');
    recordVisit($this->tour, [$this->lounge, $this->pool], '2026-11-14 10:00', 60, 'c');
    recordVisit($other, [], '2026-11-15 10:00', 10, 'd');
    recordVisit($this->tour, [$this->lounge], '2026-11-09 10:00', 60, 'e');
    recordVisit($this->tour, [$this->lounge], '2026-11-08 10:00', 60, 'f');
    recordLead($this->tour, '2026-11-17 10:00');

    $this->actingAs($this->owner)
        ->get(route('analytics.index', ['range' => 7]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('advanced.comparison.views.current', 5)
            ->where('advanced.comparison.views.previous', 2)
            ->where('advanced.comparison.views.change', 150)
            ->where('advanced.comparison.unique_visitors.current', 4)
            ->where('advanced.comparison.leads.change', null)
            ->where('advanced.tours.0.title', 'Seaside Villa')
            ->where('advanced.tours.0.views', 4)
            ->where('advanced.tours.0.leads', 1)
            ->where('advanced.tours.0.conversion_rate', 25)
            ->where('advanced.tours.0.completion_rate', 50)
            ->where('advanced.tours.1.title', 'City Loft')
            ->where('advanced.funnel', null)
            // Tuesday 17 November, 09:00 → weekday index 1, hour 9.
            ->where('advanced.heatmap.1.9', 2)
            ->where('advanced.heatmap.0.21', 1));
});

test('a single tour shows where visitors drop off and which hotspots they click', function () {
    analyticsPlan(true);
    $hotspot = TourHotspot::factory()->create(['tour_scene_id' => $this->lounge->id, 'title' => 'Book a viewing', 'type' => 'link']);

    $visit = recordVisit($this->tour, [$this->lounge, $this->pool], '2026-11-17 09:00');
    recordVisit($this->tour, [$this->lounge], '2026-11-17 10:00');
    recordVisit($this->tour, [$this->lounge], '2026-11-17 11:00');
    recordVisit($this->tour, [], '2026-11-17 12:00');
    TourEvent::create(['tour_visit_id' => $visit->id, 'virtual_tour_id' => $this->tour->id, 'type' => 'link_click', 'tour_hotspot_id' => $hotspot->id, 'created_at' => now()->subDay()]);

    $this->actingAs($this->owner)
        ->get(route('analytics.index', ['tour' => $this->tour->ulid, 'range' => 7]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('advanced.tours', [])
            ->where('advanced.funnel.0.label', 'Lounge')
            ->where('advanced.funnel.0.percent', 75)
            ->where('advanced.funnel.1.label', 'Pool')
            ->where('advanced.funnel.1.percent', 25)
            ->where('advanced.hotspots.0.label', 'Book a viewing')
            ->where('advanced.hotspots.0.scene', 'Lounge')
            ->where('advanced.hotspots.0.clicks', 1));
});

test('daily totals export as CSV', function () {
    analyticsPlan(true);
    recordVisit($this->tour, [$this->lounge], '2026-11-17 09:00', 40, 'a');
    recordVisit($this->tour, [$this->lounge, $this->pool], '2026-11-17 15:00', 80, 'b');
    recordLead($this->tour, '2026-11-17 16:00');

    $csv = $this->actingAs($this->owner)
        ->get(route('analytics.export', ['type' => 'visits', 'range' => 7]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->streamedContent();

    expect($csv)->toContain('Date,Tour,Views,"Unique visitors"')
        ->toContain('2026-11-17,"Seaside Villa",2,2,60,1,1');
});

test('only owners and admins can export enquiries, and formulas are neutralised', function () {
    analyticsPlan(true);
    recordLead($this->tour, '2026-11-17 16:00', ['name' => '=HYPERLINK("http://evil.test")']);

    $csv = $this->actingAs($this->owner)->get(route('analytics.export', ['type' => 'leads', 'range' => 7]))->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK");

    $contacts = $this->get(route('analytics.export', ['type' => 'contacts', 'range' => 7]))->assertOk()->streamedContent();
    expect($contacts)->toStartWith('Name,Email')->not->toContain('Message');

    $editor = User::factory()->create();
    $this->workspace->addMember($editor, WorkspaceRole::Editor);
    $editor->switchWorkspace($this->workspace);

    $this->actingAs($editor)->get(route('analytics.export', ['type' => 'leads']))->assertForbidden();
    $this->actingAs($editor)->get(route('analytics.export', ['type' => 'contacts']))->assertForbidden();
    $this->actingAs($editor)->get(route('analytics.export', ['type' => 'visits']))->assertOk();
});

test('the videos tab totals views, plays, completions and downloads', function () {
    VideoProject::factory()->inWorkspace($this->workspace)->create(['title' => 'Teaser', 'final_asset_id' => MediaAsset::factory()->create(['workspace_id' => $this->workspace->id])->id, 'views_count' => 10, 'embed_views_count' => 5, 'plays_count' => 8, 'completions_count' => 2, 'downloads_count' => 1]);
    VideoProject::factory()->inWorkspace($this->workspace)->create(['title' => 'Draft']);

    $this->actingAs($this->owner)
        ->get(route('analytics.index', ['tab' => 'videos']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('videos.items', 1)
            ->where('videos.items.0.title', 'Teaser')
            ->where('videos.items.0.completion_rate', 25)
            ->where('videos.totals.plays', 8)
            ->where('videos.totals.downloads', 1));
});
