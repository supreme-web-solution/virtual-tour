<?php

use App\Contracts\Media\MediaStorage;
use App\Enums\AssetSource;
use App\Jobs\PublishToStreetView;
use App\Models\GoogleConnection;
use App\Models\MediaAsset;
use App\Models\Plan;
use App\Models\StreetViewPublication;
use App\Models\Subscription;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\Billing\EntitlementService;
use App\Services\StreetView\PanoramaXmp;
use App\Services\StreetView\StreetViewPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMediaStorage;

function streetViewPlan(bool $enabled = true): void
{
    Subscription::query()->where('workspace_id', test()->workspace->id)->delete();
    $plan = Plan::factory()->with(['street_view' => $enabled])->create();
    Subscription::factory()->create(['workspace_id' => test()->workspace->id, 'plan_id' => $plan->id]);
    app(EntitlementService::class)->forget();
}

function realScene(array $asset = [], array $scene = []): TourScene
{
    $media = MediaAsset::factory()->create([
        'workspace_id' => test()->workspace->id,
        'source_type' => AssetSource::Upload,
        'width' => 8000,
        'height' => 4000,
        'metadata' => ['panorama' => ['is_equirectangular' => true]],
        ...$asset,
    ]);

    return TourScene::factory()->forTour(test()->tour)->create(['media_asset_id' => $media->id, 'latitude' => 51.5, 'longitude' => -0.12, ...$scene]);
}

function streetViewJpeg(): string
{
    ob_start();
    imagejpeg(imagecreatetruecolor(400, 200));

    return (string) ob_get_clean();
}

beforeEach(function () {
    $this->app->instance(MediaStorage::class, new FakeMediaStorage);
    config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->tour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    streetViewPlan();
});

test('the photo sphere metadata is written once and the image stays valid', function () {
    $xmp = app(PanoramaXmp::class);
    $once = $xmp->embed(streetViewJpeg(), 400, 200);
    $twice = $xmp->embed($once, 400, 200);

    expect(substr_count($twice, 'GPano:ProjectionType'))->toBe(2) // opening and closing tag
        ->and($twice)->toContain('<GPano:FullPanoWidthPixels>400<')
        ->and(getimagesizefromstring($twice)[0])->toBe(400);
});

test('only large, real, placed panoramas are eligible', function () {
    $publisher = app(StreetViewPublisher::class);

    expect($publisher->problem(realScene()->load('mediaAsset')))->toBeNull()
        ->and($publisher->problem(realScene(['source_type' => AssetSource::Generated])->load('mediaAsset')))->toContain('own uploaded')
        ->and($publisher->problem(realScene(['metadata' => ['panorama' => ['is_equirectangular' => true], 'ai' => ['synthetic' => true]]])->load('mediaAsset')))->toContain('own uploaded')
        ->and($publisher->problem(realScene(['width' => 2000])->load('mediaAsset')))->toContain('Too small')
        ->and($publisher->problem(realScene(['metadata' => []])->load('mediaAsset')))->toContain('360')
        ->and($publisher->problem(realScene([], ['latitude' => null])->load('mediaAsset')))->toContain('map');
});

test('publishing needs a Google connection and queues eligible scenes', function () {
    Queue::fake();
    $scene = realScene();
    $bad = realScene(['width' => 1000]);
    $url = route('tours.street-view.store', $this->tour);

    $this->actingAs($this->owner)->postJson($url, ['scene_ids' => [$scene->ulid]])->assertUnprocessable();

    GoogleConnection::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)->postJson($url, ['scene_ids' => [$scene->ulid, $bad->ulid]])
        ->assertStatus(202)
        ->assertJsonStructure(['connection' => ['email']]);

    expect(StreetViewPublication::count())->toBe(1);
    Queue::assertPushed(PublishToStreetView::class, 1);
});

test('the plan must include Street View', function () {
    streetViewPlan(false);
    GoogleConnection::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)->postJson(route('tours.street-view.store', $this->tour), ['scene_ids' => [realScene()->ulid]])->assertUnprocessable();
    $this->actingAs($this->owner)->getJson(route('tours.street-view.show', $this->tour))->assertJsonPath('allowed', false);
});

test('a scene is uploaded to Google and can be removed again', function () {
    GoogleConnection::factory()->create(['workspace_id' => $this->workspace->id, 'refresh_token' => 'refresh']);
    $scene = realScene();

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access']),
        'storage.test/*' => Http::response(streetViewJpeg()),
        'streetviewpublish.googleapis.com/v1/photo:startUpload' => Http::response(['uploadUrl' => 'https://streetviewpublish.googleapis.com/media/user/1/photo/up1']),
        'streetviewpublish.googleapis.com/media/*' => Http::response([]),
        'streetviewpublish.googleapis.com/v1/photo/P1' => Http::response([]),
        'streetviewpublish.googleapis.com/v1/photo' => Http::response(['photoId' => ['id' => 'P1'], 'shareLink' => 'https://maps.google.com/?photo=P1']),
    ]);

    $publication = app(StreetViewPublisher::class)->queue($scene, $this->workspace->id);
    app(StreetViewPublisher::class)->run($publication->refresh());

    expect($publication->refresh())->status->toBe('published')->photo_id->toBe('P1')->share_link->toContain('P1');

    Http::assertSent(fn ($request) => $request->url() === 'https://streetviewpublish.googleapis.com/v1/photo'
        && $request['pose']['latLngPair'] === ['latitude' => 51.5, 'longitude' => -0.12]);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/media/user/') && str_contains($request->body(), 'GPano:ProjectionType'));

    $this->actingAs($this->owner)->deleteJson(route('tours.street-view.destroy', [$this->tour, $scene->ulid]))->assertOk();

    expect(StreetViewPublication::count())->toBe(0);
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/photo/P1'));
});

test('a refused upload is recorded for the user to read', function () {
    GoogleConnection::factory()->create(['workspace_id' => $this->workspace->id]);
    $scene = realScene();

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access']),
        'storage.test/*' => Http::response(streetViewJpeg()),
        'streetviewpublish.googleapis.com/*' => Http::response(['error' => ['message' => 'API not enabled']], 403),
    ]);

    $publication = app(StreetViewPublisher::class)->queue($scene, $this->workspace->id);
    app(StreetViewPublisher::class)->run($publication->refresh());

    expect($publication->refresh())->status->toBe('failed')->error->toContain('Reconnect Google');
});

test('connecting Google sends the user to Google and stores the refresh token', function () {
    $redirect = $this->actingAs($this->owner)->get(route('google.connect', ['return' => '/tours/x/edit']))->assertRedirect();
    $location = $redirect->headers->get('Location');

    expect($location)->toStartWith('https://accounts.google.com/')->toContain(urlencode('https://www.googleapis.com/auth/streetviewpublish'));
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r1', 'scope' => 'openid email https://www.googleapis.com/auth/streetviewpublish']),
        'openidconnect.googleapis.com/*' => Http::response(['email' => 'agent@example.com']),
    ]);

    $this->actingAs($this->owner)->get(route('google.callback', ['code' => 'c', 'state' => $query['state']]))->assertRedirect('/tours/x/edit');

    $connection = GoogleConnection::where('workspace_id', $this->workspace->id)->sole();
    expect($connection->google_email)->toBe('agent@example.com')->and($connection->refresh_token)->toBe('r1');
    expect(DB::table('google_connections')->value('refresh_token'))->not->toBe('r1');
});

test('a callback with the wrong state connects nothing', function () {
    $this->actingAs($this->owner)->get(route('google.connect'));

    $this->actingAs($this->owner)->get(route('google.callback', ['code' => 'c', 'state' => 'forged']))->assertRedirect(route('dashboard'));

    expect(GoogleConnection::count())->toBe(0);
});
