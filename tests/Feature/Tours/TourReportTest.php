<?php

use App\Enums\TourVisibility;
use App\Models\TourReport;
use App\Models\User;
use App\Models\VirtualTour;

beforeEach(function () {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->tour = VirtualTour::factory()->inWorkspace($this->owner->currentWorkspace)->published()->create();
    $this->url = route('tours.public.reports', $this->tour->public_id);
});

test('a visitor can report a public tour without signing in', function () {
    $this->postJson($this->url, ['reason' => 'copyright', 'details' => 'These are my photos.'])
        ->assertCreated();

    expect(TourReport::sole())
        ->virtual_tour_id->toBe($this->tour->id)
        ->reason->toBe('copyright')
        ->details->toBe('These are my photos.')
        ->resolved_at->toBeNull();
});

test('a report needs a known reason', function () {
    $this->postJson($this->url, [])->assertJsonValidationErrors('reason');
    $this->postJson($this->url, ['reason' => 'dislike'])->assertJsonValidationErrors('reason');

    expect(TourReport::count())->toBe(0);
});

test('the same visitor cannot flood a tour with reports', function () {
    $this->postJson($this->url, ['reason' => 'other'])->assertCreated();
    $this->postJson($this->url, ['reason' => 'other'])->assertCreated();

    expect(TourReport::count())->toBe(1);
});

test('bots that fill the hidden field are rejected', function () {
    $this->postJson($this->url, ['reason' => 'other', 'website' => 'https://spam.test'])->assertJsonValidationErrors('website');

    expect(TourReport::count())->toBe(0);
});

test('unpublished and private tours cannot be reported', function () {
    $draft = VirtualTour::factory()->inWorkspace($this->owner->currentWorkspace)->create();
    $private = VirtualTour::factory()->inWorkspace($this->owner->currentWorkspace)->published(TourVisibility::Private)->create();

    $this->postJson(route('tours.public.reports', $draft->public_id), ['reason' => 'other'])->assertNotFound();
    $this->postJson(route('tours.public.reports', $private->public_id), ['reason' => 'other'])->assertNotFound();
});

test('platform admins can review and resolve reports; others cannot', function () {
    $report = TourReport::factory()->create(['virtual_tour_id' => $this->tour->id, 'reason' => 'gambling']);
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($this->owner)->get(route('admin.reports.index'))->assertForbidden();

    $this->actingAs($admin)
        ->get(route('admin.reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Reports')->has('reports.data', 1)->where('openCount', 1));

    $this->patch(route('admin.reports.update', $report), ['resolved' => true]);

    expect($report->fresh()->resolved_at)->not->toBeNull();

    $this->get(route('admin.reports.index'))->assertInertia(fn ($page) => $page->has('reports.data', 0)->where('openCount', 0));
});
