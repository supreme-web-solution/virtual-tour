<?php

use App\Contracts\AI\StructuredOutputProvider;
use App\Contracts\Media\MediaStorage;
use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\WorkspaceRole;
use App\Exceptions\ProviderException;
use App\Jobs\Panorama\PollPanoramaGeneration;
use App\Jobs\Panorama\SubmitPanoramaGeneration;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\PropertyImport;
use App\Models\TourHotspot;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\Credits\CreditService;
use App\Services\Import\HostResolver;
use App\Services\Panorama\PanoramaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeHostResolver;
use Tests\Fakes\FakeMediaStorage;
use Tests\Fakes\FakePanoramaProvider;
use Tests\Fakes\FakeStructuredOutputProvider;

beforeEach(function () {
    config([
        'ai.operations.scene_plan.credits' => 1,
        'ai.operations.photo_analysis.credits' => 1,
        'ai.operations.text_to_panorama.credits' => 5,
        'ai.operations.photo_to_panorama.credits' => 5,
    ]);

    // Panorama submission and polling are driven by hand; everything else runs.
    Queue::fake([SubmitPanoramaGeneration::class, PollPanoramaGeneration::class]);

    $this->storage = new FakeMediaStorage;
    $this->panoramaProvider = new FakePanoramaProvider;
    $this->app->instance(MediaStorage::class, $this->storage);
    $this->app->instance(PanoramaGenerationProvider::class, $this->panoramaProvider);
    $this->app->instance(StructuredOutputProvider::class, new FakeStructuredOutputProvider([
        'tour_title' => 'Marina Sky Penthouse',
        'tour_description' => 'A penthouse above the marina.',
        'scenes' => [
            ['key' => 'living', 'title' => 'Living Room', 'room_type' => 'living_room', 'description' => 'Double height', 'panorama_prompt' => 'A double-height living room with floor-to-ceiling windows at sunset', 'negative_prompt' => 'people', 'suggested_order' => 1, 'connections' => ['terrace']],
            ['key' => 'terrace', 'title' => 'Terrace', 'room_type' => 'terrace', 'description' => 'Pool terrace', 'panorama_prompt' => 'A rooftop terrace with an infinity pool above the marina', 'negative_prompt' => 'people', 'suggested_order' => 2, 'connections' => ['living']],
        ],
    ]));

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->credits = app(CreditService::class);
    $this->credits->grant($this->workspace->id, 30, 'Test');
});

/**
 * Let every queued panorama finish at the (fake) provider and be stored.
 */
function finishPanoramas(FakePanoramaProvider $provider): void
{
    $service = app(PanoramaService::class);

    AiGeneration::whereIn('generation_type', [GenerationType::TextToPanorama, GenerationType::PhotoToPanorama])
        ->where('status', GenerationStatus::Queued)
        ->get()
        ->each(function (AiGeneration $generation) use ($service, $provider): void {
            $service->submit($generation);
            $generation->refresh();
            $provider->complete($generation->provider_request_id);
            $service->applyStatus($generation, $provider->getStatus($generation->provider_request_id));
            $service->store($generation->refresh());
        });
}

function photosIn($workspace, int $count, array $attributes = []): array
{
    return MediaAsset::factory()->count($count)->create(['workspace_id' => $workspace->id, ...$attributes])->pluck('ulid')->all();
}

test('describing a space plans the scenes, generates them and links them in one go', function () {
    $response = $this->actingAs($this->owner)
        ->postJson(route('tours.create.describe'), [
            'description' => 'A 320 m² sky penthouse with a double-height living room and a pool terrace.',
            'scene_count' => 2,
            'property_type' => 'apartment',
        ])
        ->assertCreated()
        ->assertJsonPath('tour.stage', 'generating')
        ->assertJsonPath('tour.total', 2);

    $tour = VirtualTour::where('ulid', $response->json('tour.id'))->sole();
    expect($tour->title)->toBe('Marina Sky Penthouse')
        ->and(AiGeneration::where('generation_type', GenerationType::TextToPanorama)->count())->toBe(2)
        ->and($this->credits->available($this->workspace->id))->toBe(30 - 1 - 10);

    finishPanoramas($this->panoramaProvider);

    $this->getJson(route('tours.progress', $tour))
        ->assertOk()
        ->assertJsonPath('tour.stage', 'ready')
        ->assertJsonPath('tour.scene_count', 2)
        ->assertJsonPath('tour.scenes.0.title', 'Living Room')
        ->assertJsonPath('tour.is_finished', true);

    // The plan's connections became navigation hotspots both ways.
    expect(TourHotspot::count())->toBe(2);
});

test('a failed plan stops the build with a reason and returns the credit', function () {
    $this->app->instance(StructuredOutputProvider::class, new FakeStructuredOutputProvider(new ProviderException('The model is overloaded')));

    $response = $this->actingAs($this->owner)->postJson(route('tours.create.describe'), [
        'description' => 'A cosy two-bedroom cottage with a garden and a fireplace.',
        'scene_count' => 3,
    ])->assertCreated();

    $this->getJson(route('tours.progress', $response->json('tour.id')))
        ->assertJsonPath('tour.stage', 'failed')
        ->assertJsonPath('tour.error', fn (string $error): bool => str_contains($error, 'overloaded'));

    expect($this->credits->available($this->workspace->id))->toBe(30);
});

test('uploaded 360° photos become a linked tour straight away, for free', function () {
    $panoramas = photosIn($this->workspace, 3, ['asset_type' => 'panorama', 'width' => 8000, 'height' => 4000, 'original_filename' => 'courtyard_360.jpg']);

    $response = $this->actingAs($this->owner)
        ->postJson(route('tours.create.panoramas'), ['panorama_ids' => $panoramas])
        ->assertCreated()
        ->assertJsonPath('tour.stage', 'ready')
        ->assertJsonPath('tour.scene_count', 3)
        ->assertJsonPath('tour.scenes.0.title', 'Courtyard');

    $tour = VirtualTour::where('ulid', $response->json('tour.id'))->sole();

    // First and last scenes link to one neighbour, the middle one to both.
    expect($tour->scenes()->orderBy('sort_order')->get()->map(fn ($scene) => $scene->hotspots()->count())->all())->toBe([1, 2, 1])
        ->and($this->credits->available($this->workspace->id))->toBe(30);
});

test('photos are read by AI, then each becomes a 360° scene in walkthrough order', function () {
    $photos = photosIn($this->workspace, 3);
    $this->app->instance(StructuredOutputProvider::class, new FakeStructuredOutputProvider([
        'property_type' => 'villa',
        'summary' => 'A villa',
        'images' => [
            ['image_number' => 1, 'title' => 'Kitchen', 'room_type' => 'kitchen', 'indoor' => true, 'description' => 'A marble kitchen', 'features' => ['island'], 'quality' => 'good', 'quality_notes' => '', 'duplicate_of' => null, 'observed_facts' => [], 'inferred_suggestions' => []],
            ['image_number' => 2, 'title' => 'Terrace', 'room_type' => 'terrace', 'indoor' => false, 'description' => 'A sea-view terrace', 'features' => [], 'quality' => 'good', 'quality_notes' => '', 'duplicate_of' => null, 'observed_facts' => [], 'inferred_suggestions' => []],
            ['image_number' => 3, 'title' => 'Kitchen again', 'room_type' => 'kitchen', 'indoor' => true, 'description' => 'Same kitchen', 'features' => [], 'quality' => 'poor', 'quality_notes' => '', 'duplicate_of' => 1, 'observed_facts' => [], 'inferred_suggestions' => []],
        ],
        'suggested_order' => [2, 1, 3],
        'suggested_connections' => [['from' => 1, 'to' => 2]],
    ]));

    $response = $this->actingAs($this->owner)
        ->postJson(route('tours.create.photos'), ['photo_ids' => $photos])
        ->assertCreated()
        ->assertJsonPath('tour.stage', 'generating');

    // The duplicate photo is skipped; the order follows the analysis.
    $generations = AiGeneration::where('generation_type', GenerationType::PhotoToPanorama)->orderBy('id')->get();
    expect($generations->map(fn ($generation) => $generation->input('title'))->all())->toBe(['Terrace', 'Kitchen'])
        ->and($generations[1]->input('prompt'))->toContain('A marble kitchen')
        ->and($this->credits->available($this->workspace->id))->toBe(30 - 1 - 10);

    finishPanoramas($this->panoramaProvider);

    $this->getJson(route('tours.progress', $response->json('tour.id')))->assertJsonPath('tour.stage', 'ready');
    expect(TourHotspot::count())->toBe(2);
});

test('without AI, photos are converted directly and still linked in order', function () {
    $this->app->instance(StructuredOutputProvider::class, new FakeStructuredOutputProvider([], configured: false));
    $photos = photosIn($this->workspace, 2);

    $response = $this->actingAs($this->owner)
        ->postJson(route('tours.create.photos'), ['photo_ids' => $photos])
        ->assertCreated()
        ->assertJsonPath('tour.stage', 'generating');

    finishPanoramas($this->panoramaProvider);

    $this->getJson(route('tours.progress', $response->json('tour.id')))->assertJsonPath('tour.stage', 'ready');
    expect(TourHotspot::count())->toBe(2)
        ->and($this->credits->available($this->workspace->id))->toBe(30 - 10);
});

test('AI ways in explain what is missing, and 360° photos work without them', function () {
    $this->app->instance(PanoramaGenerationProvider::class, new FakePanoramaProvider(configured: false));

    $this->actingAs($this->owner)
        ->postJson(route('tours.create.describe'), ['description' => 'A three-bedroom family home with a big garden.', 'scene_count' => 3])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mode');

    $this->getJson(route('tours.create.options'))
        ->assertOk()
        ->assertJsonPath('features.panoramas', false)
        ->assertJsonPath('features.ai', true);

    $this->postJson(route('tours.create.panoramas'), ['panorama_ids' => photosIn($this->workspace, 1, ['asset_type' => 'panorama'])])
        ->assertCreated();
});

test('a build that needs more credits than available does not start', function () {
    $this->credits->wallet($this->workspace->id)->forceFill(['balance' => 5])->save();

    $this->actingAs($this->owner)
        ->postJson(route('tours.create.describe'), ['description' => 'A large office floor with meeting rooms and a lounge.', 'scene_count' => 4])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credits' => 'This needs about 21 credits and you have 5.']);

    expect(VirtualTour::count())->toBe(0);
});

test('only editors create tours, only from their own media, and progress stays private', function () {
    $viewer = User::factory()->create();
    $this->workspace->addMember($viewer, WorkspaceRole::Viewer);
    $viewer->switchWorkspace($this->workspace);

    $this->actingAs($viewer)
        ->postJson(route('tours.create.panoramas'), ['panorama_ids' => photosIn($this->workspace, 1, ['asset_type' => 'panorama'])])
        ->assertForbidden();

    $foreign = MediaAsset::factory()->create(['asset_type' => 'panorama']);
    $this->actingAs($this->owner)
        ->postJson(route('tours.create.panoramas'), ['panorama_ids' => [$foreign->ulid]])
        ->assertJsonValidationErrors('panorama_ids.0');

    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    $outsider = User::factory()->withWorkspace()->create();
    $this->actingAs($outsider)->getJson(route('tours.progress', $tour))->assertForbidden();
});

test('a listing link becomes a property and a tour of its chosen photos', function () {
    $this->app->instance(HostResolver::class, new FakeHostResolver);
    $this->app->instance(StructuredOutputProvider::class, new FakeStructuredOutputProvider([], configured: false));
    $image = imagecreatetruecolor(1200, 800);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    Http::fake([
        'https://cdn.example.com/robots.txt' => fn () => Http::response('', 404),
        'https://cdn.example.com/photos/*' => fn () => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $import = PropertyImport::factory()->ready()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id]);

    $response = $this->actingAs($this->owner)
        ->postJson(route('tours.create.link', $import), [
            'target' => 'new',
            'details' => $import->details,
            'image_urls' => ['https://cdn.example.com/photos/living.jpg', 'https://cdn.example.com/photos/kitchen.jpg'],
            'rights_confirmed' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('tour.stage', 'generating')
        ->assertJsonPath('tour.total', 2);

    $tour = VirtualTour::where('ulid', $response->json('tour.id'))->sole();
    expect($tour->property?->name)->toBe('Lekki Garden Villa')
        ->and($import->refresh()->status)->toBe('completed');

    finishPanoramas($this->panoramaProvider);

    $this->getJson(route('tours.progress', $tour))
        ->assertJsonPath('tour.stage', 'ready')
        ->assertJsonPath('tour.scene_count', 2);
});

test('the old import page and property form pages now open dialogs', function () {
    $this->actingAs($this->owner);

    $this->get(route('imports.index'))->assertRedirect(route('dashboard', ['create' => 'link']));
    $this->get(route('properties.create'))->assertRedirect(route('properties.index', ['create' => 1]));
});

test('without AI 360° generation, photos become scenes as they are, for free', function () {
    $this->app->instance(PanoramaGenerationProvider::class, new FakePanoramaProvider(configured: false));
    $photos = photosIn($this->workspace, 2);

    $this->actingAs($this->owner)
        ->postJson(route('tours.create.photos'), ['photo_ids' => $photos])
        ->assertCreated()
        ->assertJsonPath('tour.stage', 'ready')
        ->assertJsonPath('tour.scene_count', 2);

    expect(AiGeneration::count())->toBe(0)
        ->and($this->credits->available($this->workspace->id))->toBe(30);
});

test('photos can opt out of 360° conversion', function () {
    $this->actingAs($this->owner)
        ->postJson(route('tours.create.photos'), ['photo_ids' => photosIn($this->workspace, 2), 'convert' => false])
        ->assertCreated()
        ->assertJsonPath('tour.stage', 'ready');

    expect(AiGeneration::count())->toBe(0)
        ->and($this->credits->available($this->workspace->id))->toBe(30);
});

test('flat photos added in the editor are converted to 360° scenes', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    $flat = photosIn($this->workspace, 1, ['asset_type' => 'panorama']);

    $this->actingAs($this->owner)
        ->postJson(route('tours.scenes.store', $tour), ['media_asset_ids' => $flat])
        ->assertCreated()
        ->assertJsonPath('converting', 1);

    expect($tour->scenes()->count())->toBe(0)
        ->and(AiGeneration::where('generation_type', GenerationType::PhotoToPanorama)->count())->toBe(1);
});
