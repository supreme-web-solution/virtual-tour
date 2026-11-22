<?php

use App\Contracts\Media\MediaStorage;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VirtualTour;
use Tests\Fakes\FakeMediaStorage;

beforeEach(function () {
    $this->app->instance(MediaStorage::class, new FakeMediaStorage);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->tour = VirtualTour::factory()->inWorkspace($this->owner->currentWorkspace)->published()->create();
    TourScene::factory()->forTour($this->tour)->create();
});

test('the viewer layout and auto-rotate speed can be set and default to carousel and normal', function () {
    $this->actingAs($this->owner)
        ->patchJson(route('tours.update', $this->tour), ['viewer_settings' => ['layout' => 'gallery', 'auto_rotate_speed' => 'slow']])
        ->assertOk()
        ->assertJsonPath('tour.viewer_settings.layout', 'gallery')
        ->assertJsonPath('tour.viewer_settings.auto_rotate_speed', 'slow');

    $fresh = VirtualTour::factory()->inWorkspace($this->owner->currentWorkspace)->create();

    expect($fresh->viewerSettings())->layout->toBe('carousel')->auto_rotate_speed->toBe('normal');
});

test('unknown layouts and speeds are refused', function () {
    $this->actingAs($this->owner)
        ->patchJson(route('tours.update', $this->tour), ['viewer_settings' => ['layout' => 'wall-of-text', 'auto_rotate_speed' => 'warp']])
        ->assertJsonValidationErrors(['viewer_settings.layout', 'viewer_settings.auto_rotate_speed']);
});

test('the public tour carries its share link and each scene description', function () {
    $this->get(route('tours.public', ['publicId' => $this->tour->public_id, 'slug' => $this->tour->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('tour.public_url', url("/t/{$this->tour->public_id}/{$this->tour->slug}"))
            ->has('tour.scenes.0.description'));
});
