<?php

use App\Contracts\AI\StructuredOutputProvider;
use App\Contracts\Media\MediaStorage;
use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Enums\AssetSource;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\HotspotType;
use App\Enums\WorkspaceRole;
use App\Exceptions\ProviderException;
use App\Jobs\AI\GenerateScenePlan;
use App\Jobs\Panorama\PollPanoramaGeneration;
use App\Jobs\Panorama\StoreGeneratedPanorama;
use App\Jobs\Panorama\SubmitPanoramaGeneration;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\TourHotspot;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\AI\GenerationLifecycle;
use App\Services\AI\ScenePlanningService;
use App\Services\Credits\CreditService;
use App\Services\Panorama\PanoramaService;
use App\Services\Panorama\PanoramaStatus;
use App\Services\Panorama\SkyboxPanoramaProvider;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMediaStorage;
use Tests\Fakes\FakePanoramaProvider;
use Tests\Fakes\FakeStructuredOutputProvider;

beforeEach(function () {
    config([
        'ai.operations.scene_plan.credits' => 1,
        'ai.operations.text_to_panorama.credits' => 5,
        'ai.operations.photo_to_panorama.credits' => 5,
    ]);

    $this->storage = new FakeMediaStorage;
    $this->panoramaProvider = new FakePanoramaProvider;
    $this->ai = new FakeStructuredOutputProvider([
        'tour_title' => 'Lagos Penthouse',
        'tour_description' => 'A bright penthouse',
        'scenes' => [
            ['key' => 'living', 'title' => 'Living Room', 'room_type' => 'living_room', 'description' => 'Open plan', 'panorama_prompt' => 'A bright open-plan living room with marble floors', 'negative_prompt' => 'people', 'suggested_order' => 1, 'connections' => ['kitchen']],
            ['key' => 'kitchen', 'title' => 'Kitchen', 'room_type' => 'kitchen', 'description' => 'Island kitchen', 'panorama_prompt' => 'A modern kitchen with a marble island', 'negative_prompt' => 'people', 'suggested_order' => 2, 'connections' => ['living']],
        ],
    ]);

    $this->app->instance(MediaStorage::class, $this->storage);
    $this->app->instance(PanoramaGenerationProvider::class, $this->panoramaProvider);
    $this->app->instance(StructuredOutputProvider::class, $this->ai);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->credits = app(CreditService::class);
    $this->credits->grant($this->workspace->id, 20, 'Test');
});

function completedPlan(User $owner): AiGeneration
{
    $plan = new AiGeneration([
        'user_id' => $owner->id,
        'generation_type' => GenerationType::ScenePlan,
        'provider' => 'openai',
        'status' => GenerationStatus::Queued,
        'input_payload' => ['description' => 'A penthouse in Lagos with sea views', 'scene_count' => 2, 'property_type' => 'apartment'],
    ]);
    $plan->workspace_id = (int) $owner->current_workspace_id;
    $plan->save();
    app(CreditService::class)->reserve($plan);

    (new GenerateScenePlan($plan))->handle(app(ScenePlanningService::class), app(GenerationLifecycle::class));

    return $plan->fresh();
}

function generatePayload(array $overrides = []): array
{
    return [
        'style_id' => 10,
        'scenes' => [
            ['key' => 'living', 'title' => 'Lounge', 'panorama_prompt' => 'A bright lounge with marble floors', 'negative_prompt' => 'people'],
            ['key' => 'kitchen', 'title' => 'Kitchen', 'panorama_prompt' => 'A modern kitchen with an island', 'negative_prompt' => null],
        ],
        'tour_mode' => 'new',
        'tour_title' => 'AI Penthouse Tour',
        ...$overrides,
    ];
}

/**
 * Run a panorama generation through submit → provider completes → store.
 */
function runPanorama(AiGeneration $generation, FakePanoramaProvider $provider): void
{
    $service = app(PanoramaService::class);
    $service->submit($generation);
    $generation->refresh();
    $provider->complete($generation->provider_request_id);
    $service->applyStatus($generation, $provider->getStatus($generation->provider_request_id));
    $service->store($generation->refresh());
}

test('describing a property reserves one credit and queues a scene plan', function () {
    Queue::fake();

    $this->actingAs($this->owner)
        ->post(route('ai-studio.plans.store'), [
            'description' => 'A modern luxury four-bedroom apartment in Lagos with marble floors',
            'property_type' => 'apartment',
            'scene_count' => 2,
        ])
        ->assertRedirect();

    $plan = AiGeneration::sole();
    expect($plan->generation_type)->toBe(GenerationType::ScenePlan)
        ->and($plan->credits_reserved)->toBe(1)
        ->and($this->credits->available($this->workspace->id))->toBe(19);

    Queue::assertPushed(GenerateScenePlan::class);
});

test('a scene plan is created from validated AI output and its credit charged', function () {
    $plan = completedPlan($this->owner);

    expect($plan->status)->toBe(GenerationStatus::Completed)
        ->and($plan->output_metadata['plan']['scenes'])->toHaveCount(2)
        ->and($plan->output_metadata['plan']['scenes'][0]['connections'])->toBe(['kitchen'])
        ->and($plan->credits_charged)->toBe(1)
        ->and($this->credits->wallet($this->workspace->id)->balance)->toBe(19);

    $this->actingAs($this->owner)
        ->get(route('ai-studio.plans.show', $plan))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('ai-studio/Plan')->where('plan.result.tour_title', 'Lagos Penthouse'));
});

test('a failed plan releases its credit', function () {
    $this->app->instance(StructuredOutputProvider::class, new FakeStructuredOutputProvider(new ProviderException('Declined')));

    $plan = completedPlan($this->owner);

    expect($plan->status)->toBe(GenerationStatus::Failed)
        ->and($plan->error_message)->toBe('Declined')
        ->and($this->credits->available($this->workspace->id))->toBe(20);
});

test('nothing is planned without enough credits', function () {
    Queue::fake();
    $poor = User::factory()->withWorkspace()->create();

    $this->actingAs($poor)
        ->post(route('ai-studio.plans.store'), ['description' => 'A modern luxury apartment in Lagos with views', 'property_type' => 'apartment', 'scene_count' => 2])
        ->assertSessionHasErrors('credits');

    expect(AiGeneration::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('confirming a plan creates a tour, holds credits for every scene and queues submissions', function () {
    Queue::fake();
    $plan = completedPlan($this->owner);

    $this->actingAs($this->owner)
        ->post(route('ai-studio.plans.generate', $plan), generatePayload())
        ->assertRedirect(route('ai-studio.plans.show', $plan));

    $tour = VirtualTour::where('title', 'AI Penthouse Tour')->sole();
    $generations = $plan->children()->get();

    expect($generations)->toHaveCount(2)
        ->and($generations->pluck('virtual_tour_id')->unique()->all())->toBe([$tour->id])
        ->and($generations->firstWhere('input_payload.key', 'living')->input('connections'))->toBe(['kitchen'])
        ->and($this->credits->available($this->workspace->id))->toBe(9);

    Queue::assertPushed(SubmitPanoramaGeneration::class, 2);
});

test('the plan must be confirmed with a known style and its own scene keys', function () {
    Queue::fake();
    $plan = completedPlan($this->owner);

    $this->actingAs($this->owner)
        ->post(route('ai-studio.plans.generate', $plan), generatePayload(['style_id' => 999]))
        ->assertSessionHasErrors('style_id');

    $this->post(route('ai-studio.plans.generate', $plan), generatePayload([
        'scenes' => [['key' => 'invented', 'title' => 'x', 'panorama_prompt' => 'A room that was never planned']],
    ]))->assertSessionHasErrors('scenes.0.key');

    expect(AiGeneration::where('generation_type', GenerationType::TextToPanorama)->count())->toBe(0);
});

test('generated panoramas are stored, added to the tour, charged, and linked when all finish', function () {
    Queue::fake();
    $plan = completedPlan($this->owner);
    $this->actingAs($this->owner)->post(route('ai-studio.plans.generate', $plan), generatePayload());

    $generations = $plan->children()->orderBy('id')->get();
    $generations->each(fn (AiGeneration $generation) => runPanorama($generation, $this->panoramaProvider));

    $tour = VirtualTour::where('title', 'AI Penthouse Tour')->sole();
    $scenes = $tour->scenes()->get();

    expect($scenes->pluck('title')->all())->toBe(['Lounge', 'Kitchen'])
        ->and(MediaAsset::where('source_type', AssetSource::Generated)->count())->toBe(2)
        ->and($scenes->first()->mediaAsset->metadata['ai']['synthetic'])->toBeTrue()
        ->and($generations->map(fn ($g) => $g->fresh()->status)->unique()->all())->toBe([GenerationStatus::Completed])
        ->and($this->credits->wallet($this->workspace->id)->balance)->toBe(9)
        ->and($this->credits->wallet($this->workspace->id)->reserved)->toBe(0)
        ->and($this->storage->fetchedUrls)->toHaveCount(2);

    $links = TourHotspot::where('type', HotspotType::Navigation)->get();
    expect($links)->toHaveCount(2)
        ->and($links->first()->settings['suggested'])->toBeTrue();
});

test('storing twice never duplicates the asset or the charge', function () {
    Queue::fake();
    $plan = completedPlan($this->owner);
    $this->actingAs($this->owner)->post(route('ai-studio.plans.generate', $plan), generatePayload());
    $generation = $plan->children()->first();

    runPanorama($generation, $this->panoramaProvider);
    app(PanoramaService::class)->store($generation->refresh());

    expect(MediaAsset::where('generation_id', $generation->id)->count())->toBe(1)
        ->and($generation->fresh()->credits_charged)->toBe(5);
});

test('a provider failure releases the scene\'s credits', function () {
    Queue::fake();
    $plan = completedPlan($this->owner);
    $this->actingAs($this->owner)->post(route('ai-studio.plans.generate', $plan), generatePayload());
    $generation = $plan->children()->first();

    $service = app(PanoramaService::class);
    $service->submit($generation);
    $service->applyStatus($generation->refresh(), new PanoramaStatus($generation->provider_request_id, PanoramaStatus::FAILED, errorMessage: 'Content policy'));

    expect($generation->fresh())->status->toBe(GenerationStatus::Failed)->error_message->toBe('Content policy')
        ->and($this->credits->available($this->workspace->id))->toBe(14);
});

test('a non-retryable submission error fails the generation without retrying', function () {
    Queue::fake();
    $this->panoramaProvider->submitError = new ProviderException('Skybox denied access.', retryable: false, status: 403);
    $plan = completedPlan($this->owner);
    $this->actingAs($this->owner)->post(route('ai-studio.plans.generate', $plan), generatePayload());
    $generation = $plan->children()->first();

    app(PanoramaService::class)->submit($generation);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed);
    Queue::assertNotPushed(PollPanoramaGeneration::class);
});

test('polling follows the provider until the panorama is ready', function () {
    Queue::fake();
    $plan = completedPlan($this->owner);
    $this->actingAs($this->owner)->post(route('ai-studio.plans.generate', $plan), generatePayload());
    $generation = $plan->children()->first();
    app(PanoramaService::class)->submit($generation);
    $generation->refresh();

    $poll = new PollPanoramaGeneration($generation);
    $poll->handle($this->panoramaProvider, app(PanoramaService::class), app(GenerationLifecycle::class));
    expect($generation->fresh()->status)->toBe(GenerationStatus::Processing);

    $this->panoramaProvider->complete($generation->provider_request_id);
    (new PollPanoramaGeneration($generation))->handle($this->panoramaProvider, app(PanoramaService::class), app(GenerationLifecycle::class));

    expect($generation->fresh()->status)->toBe(GenerationStatus::Downloading);
    Queue::assertPushed(StoreGeneratedPanorama::class);
});

test('regenerating a scene replaces its panorama in the tour', function () {
    Queue::fake();
    $plan = completedPlan($this->owner);
    $this->actingAs($this->owner)->post(route('ai-studio.plans.generate', $plan), generatePayload());
    $original = $plan->children()->orderBy('id')->first();
    runPanorama($original, $this->panoramaProvider);
    $scene = VirtualTour::where('title', 'AI Penthouse Tour')->sole()->scenes()->first();
    $firstAssetId = $scene->media_asset_id;

    $this->post(route('ai-studio.generations.regenerate', $original->fresh()), ['prompt' => 'A brighter lounge at golden hour'])
        ->assertRedirect();

    $retry = AiGeneration::latest('id')->first();
    expect($retry->input('prompt'))->toBe('A brighter lounge at golden hour');
    runPanorama($retry, $this->panoramaProvider);

    expect($scene->fresh()->media_asset_id)->not->toBe($firstAssetId)
        ->and($scene->tour->scenes()->count())->toBe(1);
});

test('cancelling stops the provider request and releases credits', function () {
    Queue::fake();
    $plan = completedPlan($this->owner);
    $this->actingAs($this->owner)->post(route('ai-studio.plans.generate', $plan), generatePayload());
    $generation = $plan->children()->first();
    app(PanoramaService::class)->submit($generation);

    $this->post(route('ai-studio.generations.cancel', $generation->fresh()))->assertRedirect();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Cancelled)
        ->and($this->panoramaProvider->cancelled)->toBe([$generation->fresh()->provider_request_id])
        ->and($this->credits->available($this->workspace->id))->toBe(14);
});

test('photo to 360 requires a Model 4 style and sends the photo', function () {
    Queue::fake();
    $photo = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->post(route('ai-studio.photo-panoramas.store'), ['photo_id' => $photo->ulid, 'prompt' => 'A bright lounge', 'style_id' => 10, 'tour_mode' => 'none'])
        ->assertSessionHasErrors('style_id');

    $this->post(route('ai-studio.photo-panoramas.store'), ['photo_id' => $photo->ulid, 'prompt' => 'A bright lounge', 'style_id' => 155, 'tour_mode' => 'none'])
        ->assertRedirect(route('ai-studio.index'));

    $generation = AiGeneration::where('generation_type', GenerationType::PhotoToPanorama)->sole();
    app(PanoramaService::class)->submit($generation);

    expect($this->panoramaProvider->submissions[0]['imageUrl'])->toBe("https://storage.test/w2048/{$photo->cloudinary_public_id}.jpg")
        ->and($generation->fresh()->virtual_tour_id)->toBeNull();
});

test('the Skybox webhook applies verified updates once', function () {
    Queue::fake();
    config(['services.skybox.api_key' => 'sky', 'services.skybox.webhook_secret' => 'whsec']);
    $this->app->instance(PanoramaGenerationProvider::class, app(SkyboxPanoramaProvider::class));
    $generation = AiGeneration::factory()->create([
        'workspace_id' => $this->workspace->id,
        'provider_request_id' => '777',
        'status' => GenerationStatus::Submitted,
    ]);
    $body = json_encode(['id' => 777, 'status' => 'complete', 'file_url' => 'https://b.test/777.jpg', 'updated_at' => '2026-11-20T10:00:00Z']);
    $headers = ['X-Signature' => hash_hmac('sha256', $body, 'whsec'), 'X-Timestamp' => (string) time(), 'Content-Type' => 'application/json'];

    $this->call('POST', route('webhooks.skybox'), [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertOk();
    $this->call('POST', route('webhooks.skybox'), [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertOk()->assertJson(['message' => 'Already processed.']);
    $this->call('POST', route('webhooks.skybox'), [], [], [], $this->transformHeadersToServerVars(['X-Signature' => 'forged', 'X-Timestamp' => (string) time()]), $body)->assertUnauthorized();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Downloading)
        ->and($generation->fresh()->output_metadata['file_url'])->toBe('https://b.test/777.jpg');
    Queue::assertPushed(StoreGeneratedPanorama::class, 1);
});

test('viewers cannot spend credits and other workspaces cannot see generations', function () {
    $viewer = User::factory()->create();
    $this->workspace->addMember($viewer, WorkspaceRole::Viewer);
    $viewer->switchWorkspace($this->workspace);
    $plan = completedPlan($this->owner);
    $outsider = User::factory()->withWorkspace()->create();

    $this->actingAs($viewer)->post(route('ai-studio.plans.store'), ['description' => 'A modern luxury apartment in Lagos', 'property_type' => 'apartment', 'scene_count' => 1])->assertForbidden();
    $this->actingAs($viewer)->post(route('ai-studio.plans.generate', $plan), generatePayload())->assertForbidden();
    $this->actingAs($viewer)->get(route('ai-studio.plans.show', $plan))->assertOk();
    $this->actingAs($outsider)->get(route('ai-studio.plans.show', $plan))->assertForbidden();
});

test('the AI Studio page shows credits and recent generations', function () {
    completedPlan($this->owner);

    $this->actingAs($this->owner)
        ->get(route('ai-studio.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ai-studio/Index')
            ->where('credits.available', 19)
            ->has('generations.data', 1));
});
