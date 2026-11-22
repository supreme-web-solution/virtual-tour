<?php

use App\Contracts\Media\MediaStorage;
use App\Enums\AssetType;
use App\Enums\WorkspaceRole;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\TourEvent;
use App\Models\TourLead;
use App\Models\TourScene;
use App\Models\TourVisit;
use App\Models\User;
use App\Models\VirtualTour;
use App\Notifications\NewTourLeadNotification;
use App\Services\Tours\TourAnalyticsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeMediaStorage;

beforeEach(function () {
    $this->app->instance(MediaStorage::class, new FakeMediaStorage);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create();
    $this->scene = TourScene::factory()->forTour($this->tour)->create(['title' => 'Lounge']);
});

function assetOfType($workspace, AssetType $type, array $attributes = []): MediaAsset
{
    return MediaAsset::factory()->create([
        'workspace_id' => $workspace->id,
        'asset_type' => $type,
        'cloudinary_resource_type' => $type === AssetType::Audio ? 'video' : 'image',
        ...$attributes,
    ]);
}

function startVisit(VirtualTour $tour, array $payload = []): TestResponse
{
    return test()->postJson(route('tours.public.visits', ['publicId' => $tour->public_id]), [
        'visitor_id' => '0b8f0c8e-2a5b-4f39-9c43-7d4b1f2e9a10',
        'referrer' => 'https://agency.example.com/listing/42',
        ...$payload,
    ]);
}

test('branding, audio, contact form and map settings save one field at a time', function () {
    $logo = assetOfType($this->workspace, AssetType::Logo);
    $track = assetOfType($this->workspace, AssetType::Audio);

    $this->actingAs($this->owner);
    $this->patchJson(route('tours.update', $this->tour), ['branding' => ['logo_asset_id' => $logo->ulid, 'primary_color' => '#AA3300']])->assertOk();
    $this->patchJson(route('tours.update', $this->tour), ['branding' => ['intro' => ['enabled' => true, 'title' => 'Welcome']]])->assertOk();
    $this->patchJson(route('tours.update', $this->tour), ['audio_settings' => ['background_asset_id' => $track->ulid, 'volume' => 40]])->assertOk();
    $response = $this->patchJson(route('tours.update', $this->tour), ['lead_settings' => ['enabled' => true, 'require_phone' => true]])->assertOk();

    $response
        ->assertJsonPath('tour.branding.logo_asset_id', $logo->ulid)
        ->assertJsonPath('tour.branding.primary_color', '#AA3300')
        ->assertJsonPath('tour.branding.intro.title', 'Welcome')
        ->assertJsonPath('tour.branding.intro.button_label', 'Start tour')
        ->assertJsonPath('tour.audio.volume', 40)
        ->assertJsonPath('tour.audio.loop', true)
        ->assertJsonPath('tour.lead_settings.require_phone', true)
        ->assertJsonPath('tour.lead_settings.notify', true);
});

test('settings reject other workspaces\' media, wrong asset types and bad colours', function () {
    $foreignLogo = MediaAsset::factory()->create(['asset_type' => AssetType::Logo]);
    $photoAsAudio = assetOfType($this->workspace, AssetType::Photo);

    $this->actingAs($this->owner)
        ->patchJson(route('tours.update', $this->tour), [
            'branding' => ['logo_asset_id' => $foreignLogo->ulid, 'primary_color' => 'red'],
            'audio_settings' => ['background_asset_id' => $photoAsAudio->ulid],
        ])
        ->assertJsonValidationErrors(['branding.logo_asset_id', 'branding.primary_color', 'audio_settings.background_asset_id']);
});

test('a scene can have its own audio that pauses the tour music', function () {
    $track = assetOfType($this->workspace, AssetType::Audio);

    $this->actingAs($this->owner)
        ->patchJson(route('tours.scenes.update', [$this->tour, $this->scene]), [
            'audio_settings' => ['asset_id' => $track->ulid, 'pause_background' => true],
        ])
        ->assertOk()
        ->assertJsonPath('tour.scenes.0.audio.asset_id', $track->ulid)
        ->assertJsonPath('tour.scenes.0.audio.url', $track->secure_url)
        ->assertJsonPath('tour.scenes.0.audio.pause_background', true);
});

test('floor plans hold one marker per scene that can be moved and removed', function () {
    $plan = assetOfType($this->workspace, AssetType::FloorPlan);
    $this->actingAs($this->owner);

    $floorplanId = $this->postJson(route('tours.floorplans.store', $this->tour), ['media_asset_id' => $plan->ulid, 'title' => 'Ground floor'])
        ->assertCreated()
        ->json('tour.floorplans.0.id');
    $markerUrl = route('tours.floorplans.markers.place', [$this->tour, $floorplanId]);

    $this->putJson($markerUrl, ['scene_id' => $this->scene->ulid, 'x' => 0.25, 'y' => 0.5])->assertOk();
    $this->putJson($markerUrl, ['scene_id' => $this->scene->ulid, 'x' => 0.75, 'y' => 0.1])
        ->assertJsonCount(1, 'tour.floorplans.0.markers')
        ->assertJsonPath('tour.floorplans.0.markers.0', ['scene_id' => $this->scene->ulid, 'x' => 0.75, 'y' => 0.1]);

    $this->putJson($markerUrl, ['scene_id' => $this->scene->ulid, 'x' => 1.5, 'y' => 0])->assertJsonValidationErrors('x');

    $this->deleteJson(route('tours.floorplans.markers.remove', [$this->tour, $floorplanId]), ['scene_id' => $this->scene->ulid])
        ->assertJsonCount(0, 'tour.floorplans.0.markers');
});

test('only floor plan images and this tour\'s scenes can be used on floor plans', function () {
    $photo = assetOfType($this->workspace, AssetType::Photo);
    $plan = assetOfType($this->workspace, AssetType::FloorPlan);
    $otherScene = TourScene::factory()->forTour(VirtualTour::factory()->inWorkspace($this->workspace)->create())->create();
    $this->actingAs($this->owner);

    $this->postJson(route('tours.floorplans.store', $this->tour), ['media_asset_id' => $photo->ulid, 'title' => 'Plan'])
        ->assertJsonValidationErrors('media_asset_id');

    $floorplanId = $this->postJson(route('tours.floorplans.store', $this->tour), ['media_asset_id' => $plan->ulid, 'title' => 'Plan'])->json('tour.floorplans.0.id');

    $this->putJson(route('tours.floorplans.markers.place', [$this->tour, $floorplanId]), ['scene_id' => $otherScene->ulid, 'x' => 0.5, 'y' => 0.5])
        ->assertJsonValidationErrors('scene_id');
});

test('the public tour exposes branding, audio and the contact form without private settings', function () {
    $logo = assetOfType($this->workspace, AssetType::Logo);
    $track = assetOfType($this->workspace, AssetType::Audio);
    $this->tour->update([
        'branding_settings' => ['logo_asset_id' => $logo->ulid, 'contact' => ['enabled' => true, 'email' => 'agent@example.com']],
        'audio_settings' => ['background_asset_id' => $track->ulid],
        'lead_settings' => ['enabled' => true],
        'map_settings' => ['enabled' => true],
    ]);

    $this->get(route('tours.public', ['publicId' => $this->tour->public_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tour.branding.logo_url', "https://storage.test/w400/{$logo->cloudinary_public_id}.jpg")
            ->where('tour.branding.contact.email', 'agent@example.com')
            ->missing('tour.branding.contact.enabled')
            ->where('tour.audio.url', $track->secure_url)
            ->where('tour.lead.submit_url', route('tours.public.leads', ['publicId' => $this->tour->public_id]))
            ->missing('tour.lead.notify')
            ->where('tour.map', null));
});

test('contact details are shown to visitors only while the toggle is on', function () {
    $this->tour->update(['branding_settings' => ['contact' => ['enabled' => false, 'email' => 'agent@example.com']]]);

    $this->get(route('tours.public', ['publicId' => $this->tour->public_id]))
        ->assertInertia(fn (Assert $page) => $page->where('tour.branding.contact', null));

    $this->actingAs($this->owner)->patchJson(route('tours.update', $this->tour), ['branding' => ['contact' => ['enabled' => true]]])->assertOk();

    $this->get(route('tours.public', ['publicId' => $this->tour->public_id]))
        ->assertInertia(fn (Assert $page) => $page->where('tour.branding.contact.email', 'agent@example.com'));

    $this->actingAs($this->owner)->patchJson(route('tours.update', $this->tour), ['branding' => ['contact' => ['enabled' => 'maybe']]])
        ->assertJsonValidationErrors('branding.contact.enabled');
});

test('the map appears only when enabled and the property has coordinates', function () {
    $property = Property::factory()->inWorkspace($this->workspace)->create(['latitude' => 6.4281, 'longitude' => 3.4219, 'city' => 'Lagos']);
    $this->tour->update(['property_id' => $property->id, 'map_settings' => ['enabled' => true]]);

    $this->get(route('tours.public', ['publicId' => $this->tour->public_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tour.map.latitude', 6.4281)
            ->where('tour.map.longitude', 3.4219));
});

test('starting a visit stores an anonymous hash, never the raw visitor ID', function () {
    $visitId = startVisit($this->tour, ['embed' => true])->assertCreated()->json('visit_id');

    $visit = TourVisit::sole();
    expect($visit->ulid)->toBe($visitId)
        ->and($visit->visitor_hash)->not->toContain('0b8f0c8e')
        ->and(strlen($visit->visitor_hash))->toBe(64)
        ->and($visit->referrer_host)->toBe('agency.example.com')
        ->and($visit->is_embed)->toBeTrue()
        ->and($visit->scene_count)->toBe(1);
});

test('visits are not recorded when analytics are off, or for unpublished tours', function () {
    $this->tour->update(['privacy_settings' => ['analytics_enabled' => false]]);
    startVisit($this->tour)->assertNoContent();

    $draft = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    startVisit($draft)->assertNotFound();

    expect(TourVisit::count())->toBe(0);
});

test('events are recorded for the visit, ignoring unknown scenes', function () {
    $visitId = startVisit($this->tour)->json('visit_id');
    $foreignScene = TourScene::factory()->create();

    $this->call('POST', route('tours.public.events', ['publicId' => $this->tour->public_id]), [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode([
        'visit_id' => $visitId,
        'events' => [
            ['type' => 'scene_view', 'scene_id' => $this->scene->ulid],
            ['type' => 'scene_view', 'scene_id' => $foreignScene->ulid],
            ['type' => 'made_up'],
        ],
    ]))->assertNoContent();

    expect(TourEvent::count())->toBe(2)
        ->and(TourEvent::whereNotNull('tour_scene_id')->count())->toBe(1)
        ->and(TourVisit::sole()->scenes_viewed)->toBe(1);
});

test('events for another tour\'s visit are ignored', function () {
    $otherTour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create();
    $visitId = startVisit($otherTour)->json('visit_id');

    $this->call('POST', route('tours.public.events', ['publicId' => $this->tour->public_id]), [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode([
        'visit_id' => $visitId,
        'events' => [['type' => 'scene_view', 'scene_id' => $this->scene->ulid]],
    ]))->assertNoContent();

    expect(TourEvent::count())->toBe(0);
});

function leadPayload(array $overrides = []): array
{
    return ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'message' => 'Is it still available?', 'form_token' => Crypt::encryptString((string) now()->subSeconds(30)->getTimestamp()), 'human_events' => 3, ...$overrides];
}

test('an enquiry is saved and the tour owner is notified', function () {
    Notification::fake();
    $this->tour->update(['lead_settings' => ['enabled' => true, 'thank_you' => 'Thanks Ada!']]);

    $this->postJson(route('tours.public.leads', ['publicId' => $this->tour->public_id]), leadPayload())
        ->assertCreated()
        ->assertJsonPath('message', 'Thanks Ada!');

    $lead = TourLead::sole();
    expect($lead)->workspace_id->toBe($this->workspace->id)->virtual_tour_id->toBe($this->tour->id);
    Notification::assertSentTo($this->owner, NewTourLeadNotification::class);
});

test('spam traps, required phone and a disabled form all stop enquiries', function () {
    Notification::fake();
    $url = route('tours.public.leads', ['publicId' => $this->tour->public_id]);

    $this->postJson($url, leadPayload())->assertNotFound();

    $this->tour->update(['lead_settings' => ['enabled' => true, 'require_phone' => true]]);
    $this->postJson($url, leadPayload(['phone' => '+234 801 234 5678', 'hp_note' => 'spam']))->assertJsonValidationErrors('hp_note');
    $this->postJson($url, leadPayload(['phone' => '+234 801 234 5678', 'form_token' => Crypt::encryptString((string) now()->getTimestamp())]))->assertJsonValidationErrors('message');
    $this->postJson($url, leadPayload(['phone' => '+234 801 234 5678', 'human_events' => 0]))->assertJsonValidationErrors('human_events');
    $this->postJson($url, leadPayload())->assertJsonValidationErrors('phone');

    expect(TourLead::count())->toBe(0);
    Notification::assertNothingSent();
});

test('no notification is sent when the owner turned alerts off', function () {
    Notification::fake();
    $this->tour->update(['lead_settings' => ['enabled' => true, 'notify' => false]]);

    $this->postJson(route('tours.public.leads', ['publicId' => $this->tour->public_id]), leadPayload())->assertCreated();

    Notification::assertNothingSent();
});

test('the analytics page reports visits and lists enquiries for the workspace only', function () {
    startVisit($this->tour);
    startVisit($this->tour, ['visitor_id' => 'another-visitor-id-1234']);
    $lead = new TourLead(['name' => 'Ada', 'email' => 'ada@example.com']);
    $lead->workspace_id = $this->workspace->id;
    $lead->virtual_tour_id = $this->tour->id;
    $lead->save();
    $outsider = User::factory()->withWorkspace()->create();

    $this->actingAs($this->owner)
        ->get(route('analytics.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('analytics/Index')
            ->where('report.views', 2)
            ->where('report.unique_visitors', 2)
            ->where('report.leads', 1)
            ->has('leads.data', 1)
            ->where('unreadLeads', 1));

    $this->actingAs($outsider)
        ->get(route('analytics.index'))
        ->assertInertia(fn (Assert $page) => $page->where('report.views', 0)->has('leads.data', 0));

    $this->actingAs($outsider)->delete(route('leads.destroy', $lead))->assertForbidden();
});

test('editors can mark enquiries read but only owners and admins delete them', function () {
    $editor = User::factory()->create();
    $this->workspace->addMember($editor, WorkspaceRole::Editor);
    $editor->switchWorkspace($this->workspace);
    $lead = new TourLead(['name' => 'Ada', 'email' => 'ada@example.com']);
    $lead->workspace_id = $this->workspace->id;
    $lead->virtual_tour_id = $this->tour->id;
    $lead->save();

    $this->actingAs($editor)->patch(route('leads.mark', $lead), ['read' => true])->assertRedirect();
    expect($lead->fresh()->read_at)->not->toBeNull();

    $this->actingAs($editor)->delete(route('leads.destroy', $lead))->assertForbidden();
    $this->actingAs($this->owner)->delete(route('leads.destroy', $lead))->assertRedirect();
    expect(TourLead::count())->toBe(0);
});

test('old visits are pruned after the retention period', function () {
    startVisit($this->tour);
    TourVisit::query()->update(['started_at' => now()->subDays(400)]);
    startVisit($this->tour);

    expect(app(TourAnalyticsService::class)->prune(395))->toBe(1)
        ->and(TourVisit::count())->toBe(1);
});

test('a test enquiry email goes to whoever receives real enquiries, and only editors can send it', function () {
    Notification::fake();

    $this->actingAs($this->owner)
        ->postJson(route('tours.lead-test', $this->tour))
        ->assertOk()
        ->assertJsonPath('to', $this->tour->leadRecipient()->email);

    Notification::assertSentTo($this->tour->leadRecipient(), NewTourLeadNotification::class);
    expect(TourLead::count())->toBe(0);

    $viewer = User::factory()->create();
    $this->workspace->addMember($viewer, WorkspaceRole::Viewer);
    $viewer->switchWorkspace($this->workspace);

    $this->actingAs($viewer)->postJson(route('tours.lead-test', $this->tour))->assertForbidden();
});
