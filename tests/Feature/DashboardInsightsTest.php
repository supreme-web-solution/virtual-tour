<?php

use App\Models\TourVisit;
use App\Models\User;
use App\Models\VirtualTour;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'Harbour Loft']);
});

function visitOn(VirtualTour $tour, string $when): void
{
    TourVisit::create([
        'virtual_tour_id' => $tour->id,
        'workspace_id' => $tour->workspace_id,
        'visitor_hash' => hash('sha256', $when.$tour->id),
        'device_type' => 'desktop',
        'scene_count' => 1,
        'started_at' => $when,
        'last_seen_at' => $when,
    ]);
}

test('the dashboard compares the last 30 days with the 30 before and ranks tours', function () {
    visitOn($this->tour, now()->subDays(2)->toDateTimeString());
    visitOn($this->tour, now()->subDays(3)->toDateTimeString());
    visitOn($this->tour, now()->subDays(40)->toDateTimeString());

    $this->actingAs($this->owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('insights.days', 30)
            ->where('insights.current.views', 2)
            ->where('insights.previous.views', 1)
            ->has('insights.daily', 30)
            ->where('topTours.0.title', 'Harbour Loft')
            ->where('topTours.0.views', 2)
            ->has('plan.credits'));
});

test('the dashboard only counts the visits of its own workspace', function () {
    $other = VirtualTour::factory()->published()->create();
    visitOn($other, now()->subDay()->toDateTimeString());

    $this->actingAs($this->owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('insights.current.views', 0)
            ->where('topTours', []));
});
