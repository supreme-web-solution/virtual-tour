<?php

use App\Contracts\Media\MediaStorage;
use App\Enums\TourVisibility;
use App\Models\MediaAsset;
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
});

function publicUrl(VirtualTour $tour, array $query = []): string
{
    return route('tours.public', ['publicId' => $tour->public_id, 'slug' => $tour->slug, ...$query]);
}

test('a published tour is viewable by anyone at its share link', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'Seaside Villa']);
    TourScene::factory()->forTour($tour)->create();

    $this->get(publicUrl($tour))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'frame-ancestors *')
        ->assertSee('<meta property="og:title" content="Seaside Villa">', false)
        ->assertDontSee('noindex', false)
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/TourViewer')
            ->where('tour.title', 'Seaside Villa')
            ->has('tour.scenes', 1)
            ->where('embed', false));
});

test('the slug is optional and cosmetic', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create();

    $this->get("/t/{$tour->public_id}")->assertOk();
    $this->get("/t/{$tour->public_id}/anything-else")->assertOk();
});

test('drafts and deleted tours are not found', function () {
    $draft = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    $deleted = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create();
    $deleted->delete();

    $this->get(publicUrl($draft))->assertNotFound();
    $this->get("/t/{$deleted->public_id}")->assertNotFound();
});

test('unlisted tours are viewable but not indexed', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->published(TourVisibility::Unlisted)->create();

    $this->get(publicUrl($tour))->assertOk()->assertSee('noindex', false);
});

test('private tours are visible only to workspace members', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->published(TourVisibility::Private)->create();
    $outsider = User::factory()->withWorkspace()->create();

    $this->get(publicUrl($tour))->assertNotFound();
    $this->actingAs($outsider)->get(publicUrl($tour))->assertNotFound();
    $this->actingAs($this->owner)->get(publicUrl($tour))->assertOk();
});

test('hidden scenes and hotspots leading to them are left out of the public tour', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create();
    $visible = TourScene::factory()->forTour($tour)->create(['sort_order' => 1]);
    $hidden = TourScene::factory()->forTour($tour)->create(['sort_order' => 2, 'is_visible' => false]);
    TourHotspot::factory()->navigation($hidden)->create(['tour_scene_id' => $visible->id]);
    TourHotspot::factory()->create(['tour_scene_id' => $visible->id, 'title' => 'Fireplace']);

    $this->get(publicUrl($tour))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tour.scenes', 1)
            ->where('tour.scenes.0.id', $visible->ulid)
            ->has('tour.scenes.0.hotspots', 1)
            ->where('tour.scenes.0.hotspots.0.title', 'Fireplace'));
});

test('allowed embed domains restrict which sites can frame the tour', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create([
        'privacy_settings' => ['allowed_embed_domains' => ['example.com']],
    ]);

    $this->get(publicUrl($tour, ['embed' => 1]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://example.com http://example.com")
        ->assertInertia(fn (Assert $page) => $page->where('embed', true));
});

test('members can preview a draft; others cannot', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    $outsider = User::factory()->withWorkspace()->create();

    $this->actingAs($this->owner)
        ->get(route('tours.preview', $tour))
        ->assertOk()
        ->assertSee('noindex', false)
        ->assertInertia(fn (Assert $page) => $page->where('preview', true));

    $this->actingAs($outsider)->get(route('tours.preview', $tour))->assertForbidden();
});

test('panoramas wider than one image can show get a tile layout', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create();
    $big = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'width' => 16384, 'height' => 8192, 'metadata' => ['panorama' => ['is_equirectangular' => true]]]);
    $normal = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'width' => 6000, 'height' => 3000, 'metadata' => ['panorama' => ['is_equirectangular' => true]]]);
    TourScene::factory()->forTour($tour)->create(['media_asset_id' => $big->id]);
    TourScene::factory()->forTour($tour)->create(['media_asset_id' => $normal->id]);

    $this->get(publicUrl($tour))->assertInertia(fn (Assert $page) => $page
        ->where('tour.scenes.0.media.tiles.cols', 32)
        ->where('tour.scenes.0.media.tiles.rows', 16)
        ->where('tour.scenes.0.media.tiles.width', 16384)
        ->where('tour.scenes.1.media.tiles', null));
});
