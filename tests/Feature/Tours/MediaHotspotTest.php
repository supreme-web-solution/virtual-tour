<?php

use App\Contracts\Media\MediaStorage;
use App\Enums\AssetType;
use App\Enums\HotspotType;
use App\Models\MediaAsset;
use App\Models\TourHotspot;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VirtualTour;
use Tests\Fakes\FakeMediaStorage;

beforeEach(function () {
    $this->app->instance(MediaStorage::class, new FakeMediaStorage);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->tour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    $this->scene = TourScene::factory()->forTour($this->tour)->create();
    $this->url = route('tours.hotspots.store', [$this->tour, $this->scene]);
});

test('a media hotspot shows a photo from the workspace library', function () {
    $photo = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'asset_type' => AssetType::Photo]);

    $this->actingAs($this->owner)
        ->postJson($this->url, ['type' => 'media', 'yaw' => 10, 'pitch' => 0, 'title' => 'Kitchen detail', 'media_asset_id' => $photo->ulid])
        ->assertCreated()
        ->assertJsonPath('tour.scenes.0.hotspots.0.type', 'media')
        ->assertJsonPath('tour.scenes.0.hotspots.0.media.id', $photo->ulid)
        ->assertJsonPath('tour.scenes.0.hotspots.0.media.kind', 'image');

    expect(TourHotspot::first())->type->toBe(HotspotType::Media)->media_asset_id->toBe($photo->id);
});

test('a media hotspot needs a library file from this workspace', function () {
    $foreign = MediaAsset::factory()->create(['asset_type' => AssetType::Photo]);
    $audio = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'asset_type' => AssetType::Audio]);

    $this->actingAs($this->owner);

    $this->postJson($this->url, ['type' => 'media', 'yaw' => 0, 'pitch' => 0])
        ->assertJsonValidationErrors('media_asset_id');
    $this->postJson($this->url, ['type' => 'media', 'yaw' => 0, 'pitch' => 0, 'media_asset_id' => $foreign->ulid])
        ->assertJsonValidationErrors('media_asset_id');
    $this->postJson($this->url, ['type' => 'media', 'yaw' => 0, 'pitch' => 0, 'media_asset_id' => $audio->ulid])
        ->assertJsonValidationErrors('media_asset_id');

    expect(TourHotspot::count())->toBe(0);
});

test('switching a media hotspot to another type drops its file', function () {
    $photo = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'asset_type' => AssetType::Photo]);
    $hotspot = TourHotspot::factory()->create([
        'tour_scene_id' => $this->scene->id,
        'type' => HotspotType::Media,
        'media_asset_id' => $photo->id,
    ]);

    $this->actingAs($this->owner)
        ->patchJson(route('tours.hotspots.update', [$this->tour, $hotspot]), ['type' => 'info', 'title' => 'Note'])
        ->assertOk();

    expect($hotspot->fresh()->media_asset_id)->toBeNull();
});

test('a pin can be styled: floor ring, size, colour, opacity, rotation and label mode', function () {
    $other = TourScene::factory()->forTour($this->tour)->create();

    $this->actingAs($this->owner)
        ->postJson($this->url, [
            'type' => 'navigation',
            'yaw' => 0,
            'pitch' => -30,
            'target_scene_id' => $other->ulid,
            'settings' => ['placement' => 'floor', 'size' => 60, 'color' => '#ff8800', 'opacity' => 0.8, 'rotation' => 45, 'label_mode' => 'hover'],
        ])
        ->assertCreated()
        ->assertJsonPath('tour.scenes.0.hotspots.0.settings.placement', 'floor')
        ->assertJsonPath('tour.scenes.0.hotspots.0.settings.size', 60)
        ->assertJsonPath('tour.scenes.0.hotspots.0.settings.color', '#ff8800')
        ->assertJsonPath('tour.scenes.0.hotspots.0.settings.label_mode', 'hover');
});

test('pins without style settings use the defaults and the colour of their type', function () {
    $this->actingAs($this->owner)
        ->postJson($this->url, ['type' => 'info', 'yaw' => 0, 'pitch' => 0, 'title' => 'Note'])
        ->assertCreated()
        ->assertJsonPath('tour.scenes.0.hotspots.0.settings.placement', 'sphere')
        ->assertJsonPath('tour.scenes.0.hotspots.0.settings.size', 36)
        ->assertJsonPath('tour.scenes.0.hotspots.0.settings.color', null)
        ->assertJsonPath('tour.scenes.0.hotspots.0.settings.label_mode', 'always');
});

test('pin style values are validated', function () {
    $this->actingAs($this->owner)
        ->postJson($this->url, [
            'type' => 'info', 'yaw' => 0, 'pitch' => 0, 'title' => 'Note',
            'settings' => ['placement' => 'ceiling', 'size' => 500, 'color' => 'red', 'opacity' => 0, 'rotation' => 400, 'label_mode' => 'never'],
        ])
        ->assertJsonValidationErrors(['settings.placement', 'settings.size', 'settings.color', 'settings.opacity', 'settings.rotation', 'settings.label_mode']);

    expect(TourHotspot::count())->toBe(0);
});

test('an information pin can use a place icon, and bad icon keys are rejected', function () {
    $this->actingAs($this->owner)
        ->postJson($this->url, ['type' => 'info', 'yaw' => 0, 'pitch' => 0, 'title' => 'Chapel', 'settings' => ['icon' => 'church']])
        ->assertCreated()
        ->assertJsonPath('tour.scenes.0.hotspots.0.settings.icon', 'church');

    $this->actingAs($this->owner)
        ->postJson($this->url, ['type' => 'info', 'yaw' => 0, 'pitch' => 0, 'title' => 'Bad', 'settings' => ['icon' => '<script>']])
        ->assertJsonValidationErrors(['settings.icon']);
});
