<?php

use App\Contracts\Media\MediaStorage;
use App\Enums\TourVisibility;
use App\Models\MediaAsset;
use App\Models\TourVisit;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VirtualTour;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeMediaStorage;

beforeEach(function () {
    $this->app->instance(MediaStorage::class, new FakeMediaStorage);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->workspace->forceFill(['channel_enabled' => true, 'channel_slug' => 'harbour-homes'])->save();
});

function addVisits(VirtualTour $tour, int $count): void
{
    foreach (range(1, $count) as $number) {
        TourVisit::create([
            'virtual_tour_id' => $tour->id,
            'workspace_id' => $tour->workspace_id,
            'visitor_hash' => hash('sha256', "{$tour->id}-{$number}"),
            'device_type' => 'desktop',
            'scene_count' => 1,
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}

test('the channel page lists only public published tours and videos', function () {
    VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'Seaside Villa']);
    VirtualTour::factory()->inWorkspace($this->workspace)->published(TourVisibility::Unlisted)->create(['title' => 'Hidden Loft']);
    VirtualTour::factory()->inWorkspace($this->workspace)->create(['title' => 'Draft Flat']);
    VideoProject::factory()->inWorkspace($this->workspace)->create([
        'title' => 'Teaser',
        'final_asset_id' => MediaAsset::factory()->create(['workspace_id' => $this->workspace->id])->id,
        'published_at' => now(),
        'visibility' => TourVisibility::Public,
    ]);

    $this->get(route('channel.show', 'harbour-homes'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Showcase')
            ->has('tours', 1)
            ->where('tours.0.title', 'Seaside Villa')
            ->where('totals', ['tours' => 1, 'videos' => 1])
            ->has('videos', 1));
});

test('the channel page does not exist until the owner turns it on', function () {
    $this->workspace->forceFill(['channel_enabled' => false])->save();

    $this->get(route('channel.show', 'harbour-homes'))->assertNotFound();
    $this->get('/c/not-a-channel')->assertNotFound();
});

test('visitors can search the channel', function () {
    VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'Seaside Villa']);
    VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'City Loft']);

    $this->get(route('channel.show', ['slug' => 'harbour-homes', 'q' => 'villa']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tours', 1)
            ->where('tours.0.title', 'Seaside Villa')
            ->where('filters.q', 'villa'));
});

test('the channel sorts by newest, popular and name', function () {
    $old = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'Zebra House', 'published_at' => now()->subDays(5)]);
    $new = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'Maple Court', 'published_at' => now()->subDay()]);
    $mid = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'Alder Lodge', 'published_at' => now()->subDays(3)]);
    addVisits($old, 5);
    addVisits($mid, 2);

    $titles = fn (string $sort) => collect(
        $this->get(route('channel.show', ['slug' => 'harbour-homes', 'sort' => $sort]))->viewData('page')['props']['tours'],
    )->pluck('title')->all();

    expect($titles('newest'))->toBe(['Maple Court', 'Alder Lodge', 'Zebra House'])
        ->and($titles('popular'))->toBe(['Zebra House', 'Alder Lodge', 'Maple Court'])
        ->and($titles('name'))->toBe(['Alder Lodge', 'Maple Court', 'Zebra House']);
});

test('the owner can publish the channel and choose its address', function () {
    $this->workspace->forceFill(['channel_enabled' => false, 'channel_slug' => null])->save();

    $this->actingAs($this->owner)
        ->patch(route('brand.channel.update'), ['channel_enabled' => true, 'channel_slug' => 'my-agency'])
        ->assertSessionHasNoErrors();

    expect($this->workspace->fresh())->channel_enabled->toBeTrue()->channel_slug->toBe('my-agency');
});

test('the channel address must be set, well formed and unique', function () {
    $other = User::factory()->withWorkspace()->create();
    $other->currentWorkspace->forceFill(['channel_slug' => 'taken-name'])->save();

    $this->actingAs($this->owner);

    $this->patch(route('brand.channel.update'), ['channel_enabled' => true, 'channel_slug' => ''])->assertSessionHasErrors('channel_slug');
    $this->patch(route('brand.channel.update'), ['channel_enabled' => true, 'channel_slug' => 'Bad Name!'])->assertSessionHasErrors('channel_slug');
    $this->patch(route('brand.channel.update'), ['channel_enabled' => true, 'channel_slug' => 'taken-name'])->assertSessionHasErrors('channel_slug');
});
