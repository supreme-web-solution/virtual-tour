<?php

use App\Contracts\Image\ImageEnhancementProvider;
use App\Contracts\Media\MediaStorage;
use App\Enums\AssetType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\Image\EnhancePhoto;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\Image\ImageEditRequest;
use App\Services\Image\ImageEditResult;
use App\Services\Image\PhotoEnhancementService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMediaStorage;

/**
 * Stand-in for OpenAI: reports itself configured and never edits anything.
 */
function fakeImageProvider(bool $configured = true): ImageEnhancementProvider
{
    return new class($configured) implements ImageEnhancementProvider
    {
        public function __construct(private bool $configured) {}

        public function name(): string
        {
            return 'openai';
        }

        public function model(): string
        {
            return 'fake-image-model';
        }

        public function isConfigured(): bool
        {
            return $this->configured;
        }

        public function enhance(ImageEditRequest $request): ImageEditResult
        {
            throw new RuntimeException('Not used in these tests.');
        }
    };
}

beforeEach(function () {
    Queue::fake();
    $this->app->instance(ImageEnhancementProvider::class, fakeImageProvider());

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    app(CreditService::class)->grant($this->workspace->id, 10, 'Test grant');
    $this->photo = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'asset_type' => AssetType::Photo]);
});

test('the studio lists the ways to edit with prices and available credits', function () {
    $this->actingAs($this->owner)
        ->getJson(route('media.enhance.options'))
        ->assertOk()
        ->assertJsonPath('configured', true)
        ->assertJsonPath('available', 10)
        ->assertJsonCount(5, 'modes')
        ->assertJsonStructure(['credits' => ['medium', 'high', 'upscale']]);
});

test('starting an edit holds credits and queues the job', function () {
    $this->actingAs($this->owner)
        ->postJson(route('media.enhance', $this->photo), ['mode' => 'enhance', 'quality' => 'medium'])
        ->assertCreated()
        ->assertJsonPath('enhancement.status', 'working')
        ->assertJsonPath('enhancement.mode', 'enhance');

    $generation = AiGeneration::where('generation_type', GenerationType::PhotoEnhance)->sole();

    expect($generation->credits_reserved)->toBeGreaterThan(0)
        ->and(app(CreditService::class)->available($this->workspace->id))->toBeLessThan(10);

    Queue::assertPushed(EnhancePhoto::class);
});

test('an edit needs a known mode and quality', function () {
    $this->actingAs($this->owner)
        ->postJson(route('media.enhance', $this->photo), ['mode' => 'cartoon', 'quality' => 'ultra'])
        ->assertJsonValidationErrors(['mode', 'quality']);

    Queue::assertNothingPushed();
});

test('panoramas cannot be edited', function () {
    $panorama = MediaAsset::factory()->panorama()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->postJson(route('media.enhance', $panorama), ['mode' => 'enhance', 'quality' => 'medium'])
        ->assertJsonValidationErrors('mode');

    expect(AiGeneration::count())->toBe(0);
});

test('nothing starts without the OpenAI key', function () {
    $this->app->instance(ImageEnhancementProvider::class, fakeImageProvider(configured: false));

    $this->actingAs($this->owner)
        ->postJson(route('media.enhance', $this->photo), ['mode' => 'enhance', 'quality' => 'medium'])
        ->assertJsonValidationErrors('mode');

    expect(AiGeneration::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('an edit is refused when credits run short', function () {
    app(CreditService::class)->grant($this->workspace->id, -10, 'Spend');

    $this->actingAs($this->owner)
        ->postJson(route('media.enhance', $this->photo), ['mode' => 'enhance', 'quality' => 'medium'])
        ->assertStatus(402);

    Queue::assertNothingPushed();
});

test('photos and edits of other workspaces are out of reach', function () {
    $foreign = MediaAsset::factory()->create(['asset_type' => AssetType::Photo]);
    $foreignEdit = AiGeneration::factory()->create(['generation_type' => GenerationType::PhotoEnhance]);

    $this->actingAs($this->owner);

    $this->postJson(route('media.enhance', $foreign), ['mode' => 'enhance', 'quality' => 'medium'])->assertNotFound();
    $this->getJson(route('media.enhancements.show', $foreignEdit))->assertNotFound();
});

test('when the queue is unreachable the credits are returned straight away', function () {
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('Connection refused'));

    $this->actingAs($this->owner)
        ->postJson(route('media.enhance', $this->photo), ['mode' => 'enhance', 'quality' => 'medium'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mode');

    expect(AiGeneration::where('generation_type', GenerationType::PhotoEnhance)->sole()->status->value)->toBe('failed')
        ->and(app(CreditService::class)->available($this->workspace->id))->toBe(10);
});

test('upscaling is refused until the fal.ai key is set', function () {
    config(['services.fal.key' => null]);

    $this->actingAs($this->owner)
        ->postJson(route('media.enhance', $this->photo), ['mode' => 'upscale', 'quality' => 'medium'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mode');

    expect(AiGeneration::count())->toBe(0);
});

test('an upscale holds its own price and is run by fal.ai', function () {
    config(['services.fal.key' => 'test-key', 'ai.providers.fal.poll_seconds' => 1]);

    $this->actingAs($this->owner)
        ->postJson(route('media.enhance', $this->photo), ['mode' => 'upscale', 'quality' => 'medium'])
        ->assertCreated()
        ->assertJsonPath('enhancement.credits', 4);

    $generation = AiGeneration::sole();
    expect($generation->provider)->toBe('fal');

    ob_start();
    imagejpeg(imagecreatetruecolor(300, 200));
    $jpeg = str_pad((string) ob_get_clean(), 2000, "\0");

    Http::fake([
        'queue.fal.run/fal-ai/esrgan' => Http::response([
            'request_id' => 'req-1',
            'status_url' => 'https://queue.fal.run/fal-ai/esrgan/requests/req-1/status',
            'response_url' => 'https://queue.fal.run/fal-ai/esrgan/requests/req-1',
        ]),
        'queue.fal.run/fal-ai/esrgan/requests/req-1/status' => Http::response(['status' => 'COMPLETED']),
        'queue.fal.run/fal-ai/esrgan/requests/req-1' => Http::response(['image' => ['url' => 'https://v3.fal.media/files/up.jpg']]),
        'v3.fal.media/*' => Http::response($jpeg),
    ]);
    $this->app->instance(MediaStorage::class, new FakeMediaStorage);

    app(PhotoEnhancementService::class)->run($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Completed);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'fal-ai/esrgan') && ($request['scale'] ?? null) === 4 && str_contains((string) ($request['image_url'] ?? ''), 'w1536'));
    expect(MediaAsset::where('generation_id', $generation->id)->sole()->original_filename)->toEndWith('-4x.jpg');
});
