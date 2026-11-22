<?php

use App\Contracts\Media\MediaStorage;
use App\Models\Property;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VirtualTour;
use Tests\Fakes\FakeMediaStorage;

beforeEach(function () {
    $this->app->instance(MediaStorage::class, new FakeMediaStorage);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
});

test('a scene can be pinned on the map and the pin removed again', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    $scene = TourScene::factory()->forTour($tour)->create();
    $url = route('tours.scenes.update', [$tour, $scene]);

    $this->actingAs($this->owner)
        ->patchJson($url, ['latitude' => 48.8584, 'longitude' => 2.2945])
        ->assertOk()
        ->assertJsonPath('tour.scenes.0.location.latitude', 48.8584);

    expect($scene->fresh())->latitude->toBe(48.8584)->longitude->toBe(2.2945);

    $this->patchJson($url, ['latitude' => null, 'longitude' => null])->assertOk();

    expect($scene->fresh())->latitude->toBeNull()->longitude->toBeNull();
});

test('map pins are validated', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    $scene = TourScene::factory()->forTour($tour)->create();
    $url = route('tours.scenes.update', [$tour, $scene]);

    $this->actingAs($this->owner);

    $this->patchJson($url, ['latitude' => 91, 'longitude' => 0])->assertJsonValidationErrors('latitude');
    $this->patchJson($url, ['latitude' => 0, 'longitude' => 181])->assertJsonValidationErrors('longitude');
    $this->patchJson($url, ['latitude' => 10])->assertJsonValidationErrors('longitude');
});

test('the public map lists the pinned visible scenes around the property', function () {
    $property = Property::factory()->inWorkspace($this->workspace)->create(['latitude' => 40.0, 'longitude' => -3.0]);
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create([
        'property_id' => $property->id,
        'map_settings' => ['enabled' => true],
    ]);
    $pinned = TourScene::factory()->forTour($tour)->create(['title' => 'Terrace', 'latitude' => 40.001, 'longitude' => -3.002]);
    TourScene::factory()->forTour($tour)->create(['title' => 'Unpinned']);
    TourScene::factory()->forTour($tour)->create(['title' => 'Hidden', 'latitude' => 40.002, 'longitude' => -3.001, 'is_visible' => false]);

    $this->get(route('tours.public', ['publicId' => $tour->public_id, 'slug' => $tour->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('tour.map.latitude', 40)
            ->has('tour.map.pins', 1)
            ->where('tour.map.pins.0.scene_id', $pinned->ulid)
            ->where('tour.map.pins.0.title', 'Terrace'));
});

test('the public map is hidden when switched off or when nothing has a location', function () {
    $off = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['map_settings' => ['enabled' => false]]);
    TourScene::factory()->forTour($off)->create(['latitude' => 10, 'longitude' => 10]);

    $nowhere = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['map_settings' => ['enabled' => true]]);
    TourScene::factory()->forTour($nowhere)->create();

    foreach ([$off, $nowhere] as $tour) {
        $this->get(route('tours.public', ['publicId' => $tour->public_id, 'slug' => $tour->slug]))
            ->assertInertia(fn ($page) => $page->where('tour.map', null));
    }
});
