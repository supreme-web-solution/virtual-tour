<?php

use App\Contracts\Media\MediaStorage;
use App\Enums\HotspotType;
use App\Enums\TourStatus;
use App\Enums\WorkspaceRole;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\TourHotspot;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VirtualTour;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeMediaStorage;

beforeEach(function () {
    $this->app->instance(MediaStorage::class, new FakeMediaStorage);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->tour = VirtualTour::factory()->inWorkspace($this->workspace)->create(['title' => 'Villa Tour']);
});

function panoramaIn($workspace, array $attributes = []): MediaAsset
{
    return MediaAsset::factory()->panorama()->create(['workspace_id' => $workspace->id, ...$attributes]);
}

function sceneIn(VirtualTour $tour, array $attributes = []): TourScene
{
    return TourScene::factory()->forTour($tour)->create($attributes);
}

test('a tour can be created and opens in the editor', function () {
    $property = Property::factory()->inWorkspace($this->workspace)->create();

    $response = $this->actingAs($this->owner)->post(route('tours.store'), [
        'title' => 'Garden Villa',
        'property_id' => $property->ulid,
    ]);

    $tour = VirtualTour::where('title', 'Garden Villa')->firstOrFail();
    $response->assertRedirect(route('tours.edit', $tour));

    expect($tour)
        ->property_id->toBe($property->id)
        ->status->toBe(TourStatus::Draft)
        ->public_id->toMatch('/^[a-z0-9]{12}$/');

    $this->get(route('tours.edit', $tour))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tours/Editor')
            ->where('tour.title', 'Garden Villa')
            ->where('can.update', true));
});

test('panoramas become scenes in the order they were chosen, with titles from file names', function () {
    $first = panoramaIn($this->workspace, ['original_filename' => 'living_room-360.jpg']);
    $second = panoramaIn($this->workspace, ['original_filename' => 'kitchen.jpg']);

    $this->actingAs($this->owner)
        ->postJson(route('tours.scenes.store', $this->tour), ['media_asset_ids' => [$second->ulid, $first->ulid]])
        ->assertCreated()
        ->assertJsonPath('tour.scenes.0.title', 'Kitchen')
        ->assertJsonPath('tour.scenes.1.title', 'Living Room 360')
        // 8000×4000 is 32 megapixels, over the 24 MP cap, so the full-size version is scaled to fit it.
        ->assertJsonPath('tour.scenes.0.media.panorama_url', "https://storage.test/w6928/{$second->cloudinary_public_id}.jpg");
});

test('only panoramas from the same workspace can become scenes', function () {
    $photo = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    $foreign = MediaAsset::factory()->panorama()->create();

    $this->actingAs($this->owner)
        ->postJson(route('tours.scenes.store', $this->tour), ['media_asset_ids' => [$photo->ulid, $foreign->ulid]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media_asset_ids.0', 'media_asset_ids.1']);
});

test('a scene\'s starting view is saved in degrees and validated', function () {
    $scene = sceneIn($this->tour);

    $this->actingAs($this->owner)
        ->patchJson(route('tours.scenes.update', [$this->tour, $scene]), [
            'initial_yaw' => -135.5,
            'initial_pitch' => 12.25,
            'initial_zoom' => 30,
        ])
        ->assertOk()
        ->assertJsonPath('tour.scenes.0.initial_view', ['yaw' => -135.5, 'pitch' => 12.25, 'zoom' => 30]);

    $this->patchJson(route('tours.scenes.update', [$this->tour, $scene]), ['initial_yaw' => 200, 'initial_pitch' => -95])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['initial_yaw', 'initial_pitch']);
});

test('scenes can be reordered only with the complete list', function () {
    $a = sceneIn($this->tour, ['sort_order' => 1]);
    $b = sceneIn($this->tour, ['sort_order' => 2]);
    $c = sceneIn($this->tour, ['sort_order' => 3]);

    $this->actingAs($this->owner)
        ->putJson(route('tours.scenes.reorder', $this->tour), ['scene_ids' => [$c->ulid, $a->ulid, $b->ulid]])
        ->assertOk();

    expect($this->tour->scenes()->pluck('ulid')->all())->toBe([$c->ulid, $a->ulid, $b->ulid]);

    $this->putJson(route('tours.scenes.reorder', $this->tour), ['scene_ids' => [$a->ulid, $b->ulid]])
        ->assertUnprocessable();
});

test('duplicating a scene copies its hotspots and places it right after', function () {
    $a = sceneIn($this->tour, ['sort_order' => 1, 'title' => 'Lounge']);
    $b = sceneIn($this->tour, ['sort_order' => 2]);
    TourHotspot::factory()->create(['tour_scene_id' => $a->id]);

    $this->actingAs($this->owner)
        ->postJson(route('tours.scenes.duplicate', [$this->tour, $a]))
        ->assertCreated()
        ->assertJsonPath('tour.scenes.1.title', 'Lounge (copy)')
        ->assertJsonCount(1, 'tour.scenes.1.hotspots')
        ->assertJsonPath('tour.scenes.2.id', $b->ulid);
});

test('a navigation hotspot links to another scene in the same tour', function () {
    $from = sceneIn($this->tour);
    $to = sceneIn($this->tour, ['title' => 'Kitchen']);

    $this->actingAs($this->owner)
        ->postJson(route('tours.hotspots.store', [$this->tour, $from]), [
            'type' => 'navigation',
            'yaw' => 45.5,
            'pitch' => -10,
            'target_scene_id' => $to->ulid,
        ])
        ->assertCreated()
        ->assertJsonPath('tour.scenes.0.hotspots.0.target_scene_id', $to->ulid)
        ->assertJsonPath('tour.scenes.0.hotspots.0.yaw', 45.5);
});

test('hotspots are validated by type', function () {
    $scene = sceneIn($this->tour);
    $otherTourScene = sceneIn(VirtualTour::factory()->inWorkspace($this->workspace)->create());
    $url = route('tours.hotspots.store', [$this->tour, $scene]);

    $this->actingAs($this->owner);

    $this->postJson($url, ['type' => 'navigation', 'yaw' => 0, 'pitch' => 0])
        ->assertJsonValidationErrors('target_scene_id');
    $this->postJson($url, ['type' => 'navigation', 'yaw' => 0, 'pitch' => 0, 'target_scene_id' => $scene->ulid])
        ->assertJsonValidationErrors('target_scene_id');
    $this->postJson($url, ['type' => 'navigation', 'yaw' => 0, 'pitch' => 0, 'target_scene_id' => $otherTourScene->ulid])
        ->assertJsonValidationErrors('target_scene_id');
    $this->postJson($url, ['type' => 'info', 'yaw' => 0, 'pitch' => 0])
        ->assertJsonValidationErrors('title');
    $this->postJson($url, ['type' => 'link', 'yaw' => 0, 'pitch' => 0, 'title' => 'Book', 'external_url' => 'javascript:alert(1)'])
        ->assertJsonValidationErrors('external_url');
    $this->postJson($url, ['type' => 'info', 'yaw' => 181, 'pitch' => 91, 'title' => 'x'])
        ->assertJsonValidationErrors(['yaw', 'pitch']);

    expect(TourHotspot::count())->toBe(0);
});

test('a hotspot can be repositioned without resending its other fields', function () {
    $scene = sceneIn($this->tour);
    $hotspot = TourHotspot::factory()->create(['tour_scene_id' => $scene->id, 'title' => 'Marble floor']);

    $this->actingAs($this->owner)
        ->patchJson(route('tours.hotspots.update', [$this->tour, $hotspot]), ['yaw' => -90, 'pitch' => 5])
        ->assertOk();

    expect($hotspot->fresh())->yaw->toBe('-90.000')->title->toBe('Marble floor')->type->toBe(HotspotType::Info);
});

test('switching a hotspot type clears fields that no longer apply', function () {
    $scene = sceneIn($this->tour);
    $hotspot = TourHotspot::factory()->create([
        'tour_scene_id' => $scene->id,
        'type' => HotspotType::Link,
        'external_url' => 'https://example.com',
    ]);

    $this->actingAs($this->owner)
        ->patchJson(route('tours.hotspots.update', [$this->tour, $hotspot]), ['type' => 'info', 'title' => 'Note'])
        ->assertOk();

    expect($hotspot->fresh()->external_url)->toBeNull();
});

test('deleting a scene removes hotspots that lead to it and clears it as the start scene', function () {
    $from = sceneIn($this->tour);
    $to = sceneIn($this->tour);
    TourHotspot::factory()->navigation($to)->create(['tour_scene_id' => $from->id]);
    $this->tour->update(['viewer_settings' => ['start_scene_id' => $to->ulid]]);

    $this->actingAs($this->owner)
        ->deleteJson(route('tours.scenes.destroy', [$this->tour, $to]))
        ->assertOk()
        ->assertJsonPath('tour.viewer_settings.start_scene_id', null);

    expect(TourHotspot::count())->toBe(0);
});

test('scenes and hotspots of another tour cannot be reached through this tour', function () {
    $otherTour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    $otherScene = sceneIn($otherTour);
    $otherHotspot = TourHotspot::factory()->create(['tour_scene_id' => $otherScene->id]);

    $this->actingAs($this->owner);

    $this->patchJson(route('tours.scenes.update', [$this->tour, $otherScene]), ['title' => 'x'])->assertNotFound();
    $this->deleteJson(route('tours.hotspots.destroy', [$this->tour, $otherHotspot]))->assertNotFound();
});

test('viewers can open the editor read-only but cannot change anything', function () {
    $viewer = User::factory()->create();
    $this->workspace->addMember($viewer, WorkspaceRole::Viewer);
    $viewer->switchWorkspace($this->workspace);
    $scene = sceneIn($this->tour);

    $this->actingAs($viewer)
        ->get(route('tours.edit', $this->tour))
        ->assertInertia(fn (Assert $page) => $page->where('can.update', false));

    $this->patchJson(route('tours.update', $this->tour), ['title' => 'Hacked'])->assertForbidden();
    $this->patchJson(route('tours.scenes.update', [$this->tour, $scene]), ['title' => 'x'])->assertForbidden();
    $this->postJson(route('tours.publish', $this->tour))->assertForbidden();
});

test('other workspaces cannot open or edit the tour', function () {
    $outsider = User::factory()->withWorkspace()->create();

    $this->actingAs($outsider)->get(route('tours.edit', $this->tour))->assertForbidden();
    $this->actingAs($outsider)->patchJson(route('tours.update', $this->tour), ['title' => 'x'])->assertForbidden();
});

test('tour settings save partially and embed domains are validated', function () {
    $this->actingAs($this->owner)
        ->patchJson(route('tours.update', $this->tour), [
            'visibility' => 'public',
            'viewer_settings' => ['auto_rotate' => true],
            'allowed_embed_domains' => ['Example.com', '*.agency.co.uk'],
        ])
        ->assertOk()
        ->assertJsonPath('tour.title', 'Villa Tour')
        ->assertJsonPath('tour.visibility', 'public')
        ->assertJsonPath('tour.viewer_settings.auto_rotate', true)
        ->assertJsonPath('tour.viewer_settings.show_scene_gallery', true)
        ->assertJsonPath('tour.viewer_settings.show_scene_label', true)
        ->assertJsonPath('tour.allowed_embed_domains', ['example.com', '*.agency.co.uk']);

    $this->patchJson(route('tours.update', $this->tour), ['viewer_settings' => ['show_scene_label' => false]])
        ->assertOk()
        ->assertJsonPath('tour.viewer_settings.show_scene_label', false);

    $this->patchJson(route('tours.update', $this->tour), ['allowed_embed_domains' => ['https://example.com/page']])
        ->assertJsonValidationErrors('allowed_embed_domains.0');
});

test('a tour needs a visible scene before it can be published', function () {
    $this->actingAs($this->owner)
        ->postJson(route('tours.publish', $this->tour))
        ->assertUnprocessable();

    sceneIn($this->tour);

    $this->postJson(route('tours.publish', $this->tour))
        ->assertOk()
        ->assertJsonPath('tour.status', 'published');

    $this->deleteJson(route('tours.unpublish', $this->tour))
        ->assertJsonPath('tour.status', 'draft');
});

test('a panorama used by a scene cannot be deleted from the library', function () {
    $scene = sceneIn($this->tour);

    $this->actingAs($this->owner)
        ->delete(route('media.destroy', $scene->mediaAsset))
        ->assertRedirect();

    expect($scene->mediaAsset->fresh())->not->toBeNull();
});

test('the tours list shows only the current workspace\'s tours', function () {
    VirtualTour::factory()->create(['title' => 'Not mine']);

    $this->actingAs($this->owner)
        ->get(route('tours.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('tours/Index')
            ->has('tours.data', 1)
            ->where('tours.data.0.title', 'Villa Tour'));
});
