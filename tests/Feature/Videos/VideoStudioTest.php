<?php

use App\Contracts\AI\StructuredOutputProvider;
use App\Contracts\Media\MediaStorage;
use App\Contracts\Music\MusicGenerationProvider;
use App\Contracts\Video\VideoGenerationProvider;
use App\Contracts\Video\VideoRenderer;
use App\Enums\AssetType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\SoundtrackMode;
use App\Enums\TourVisibility;
use App\Enums\VideoStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\ProviderException;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoShot;
use App\Services\AI\VideoPlanningService;
use App\Services\Credits\CreditService;
use App\Services\Video\VideoStudioService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeMediaStorage;
use Tests\Fakes\FakeMusicProvider;
use Tests\Fakes\FakeStructuredOutputProvider;
use Tests\Fakes\FakeVideoProvider;
use Tests\Fakes\FakeVideoRenderer;

beforeEach(function () {
    config([
        'ai.operations.video_storyboard.credits' => 1,
        'ai.operations.video_clip.credits_per_second' => 2,
        'ai.operations.music_track.credits' => 3,
        'ai.video.temp_path' => storage_path('framework/testing/renders'),
    ]);

    $this->storage = new FakeMediaStorage;
    $this->videoProvider = new FakeVideoProvider;
    $this->musicProvider = new FakeMusicProvider;
    $this->renderer = new FakeVideoRenderer;
    $this->ai = new FakeStructuredOutputProvider([
        'video_title' => 'Lekki Villa',
        'creative_direction' => 'Bright and calm.',
        'music_prompt' => 'Soft piano',
        'shots' => [
            ['image_number' => 1, 'scene_name' => 'Living Room', 'duration' => 5, 'camera_movement' => 'slow push-in', 'visual_description' => 'Lounge', 'prompt' => 'A bright lounge, gentle push towards the window.', 'transition' => 'crossfade', 'audio_notes' => ''],
            ['image_number' => 2, 'scene_name' => 'Kitchen', 'duration' => 5, 'camera_movement' => 'slow pan right', 'visual_description' => 'Kitchen', 'prompt' => 'A modern kitchen, smooth pan across the island.', 'transition' => 'fade_black', 'audio_notes' => ''],
        ],
    ]);

    $this->app->instance(MediaStorage::class, $this->storage);
    $this->app->instance(VideoGenerationProvider::class, $this->videoProvider);
    $this->app->instance(MusicGenerationProvider::class, $this->musicProvider);
    $this->app->instance(VideoRenderer::class, $this->renderer);
    $this->app->instance(StructuredOutputProvider::class, $this->ai);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->credits = app(CreditService::class);
    $this->credits->grant($this->workspace->id, 100, 'Test');
});

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/renders'));
});

/**
 * A draft project with two shots ready for clip generation.
 */
function draftVideo(array $attributes = []): VideoProject
{
    $workspace = test()->workspace;
    $project = VideoProject::factory()->inWorkspace($workspace)->create($attributes);
    $photo = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);
    $panorama = MediaAsset::factory()->panorama()->create(['workspace_id' => $workspace->id]);

    VideoShot::factory()->forProject($project)->create(['source_asset_id' => $photo->id, 'sort_order' => 1, 'duration' => 5]);
    VideoShot::factory()->forProject($project)->create(['source_asset_id' => $panorama->id, 'sort_order' => 2, 'duration' => 4, 'scene_name' => 'Kitchen']);

    return $project;
}

function videoPayload(array $sourceIds, array $overrides = []): array
{
    return [
        'title' => 'Lekki Villa teaser',
        'style' => 'property_walkthrough',
        'aspect_ratio' => '16:9',
        'duration' => 10,
        'source_ids' => $sourceIds,
        'soundtrack_mode' => 'none',
        'settings' => ['branded_outro' => false],
        ...$overrides,
    ];
}

test('creating a video plans a storyboard from the chosen images and charges one credit', function () {
    $photos = MediaAsset::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->owner)
        ->post(route('videos.store'), videoPayload($photos->pluck('ulid')->all()));

    $project = VideoProject::sole();
    $response->assertRedirect(route('videos.show', $project));

    expect($project->status)->toBe(VideoStatus::Draft)
        ->and($project->storyboard['creative_direction'])->toBe('Bright and calm.')
        ->and($project->shots()->pluck('source_asset_id')->all())->toBe($photos->pluck('id')->all())
        ->and((int) $project->shots()->sum('duration'))->toBe(10)
        ->and($this->credits->wallet($this->workspace->id)->balance)->toBe(99);

    $this->get(route('videos.show', $project))
        ->assertInertia(fn (Assert $page) => $page->component('videos/Show')->has('video.shots', 2)->where('clipCost', 20));
});

test('a failed storyboard marks the video failed and returns the credit', function () {
    $this->app->instance(StructuredOutputProvider::class, new FakeStructuredOutputProvider(new ProviderException('Declined')));
    $photo = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)->post(route('videos.store'), videoPayload([$photo->ulid]));

    $generation = AiGeneration::sole();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and(VideoProject::sole()->status)->toBe(VideoStatus::Failed)
        ->and($this->credits->available($this->workspace->id))->toBe(100);
});

test('videos only accept images from the same workspace and own audio needs a file', function () {
    Queue::fake();
    $foreign = MediaAsset::factory()->create();
    $photo = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->post(route('videos.store'), videoPayload([$foreign->ulid]))
        ->assertSessionHasErrors('source_ids.0');

    $this->post(route('videos.store'), videoPayload([$photo->ulid], ['soundtrack_mode' => 'own_audio']))
        ->assertSessionHasErrors('soundtrack_asset_id');

    $this->post(route('videos.store'), videoPayload([$photo->ulid], ['style' => 'custom']))
        ->assertSessionHasErrors('settings.brief');

    expect(VideoProject::count())->toBe(0);
});

test('the storyboard planner uses each image once and fits the requested length', function () {
    $planner = app(VideoPlanningService::class);
    $sources = [['id' => 'a', 'label' => 'A', 'image_url' => 'https://x.test/a.jpg'], ['id' => 'b', 'label' => 'B', 'image_url' => 'https://x.test/b.jpg']];
    $limits = ['min_seconds' => 3, 'max_seconds' => 10];
    $shot = fn (int $image, int $duration): array => ['image_number' => $image, 'duration' => $duration, 'prompt' => 'A slow cinematic move through the room.', 'camera_movement' => 'slow push-in', 'transition' => 'cut'];

    $storyboard = $planner->normalise(['video_title' => 'T', 'shots' => [$shot(1, 9), $shot(1, 9), $shot(2, 2)]], $sources, 15, $limits);

    expect(array_column($storyboard['shots'], 'source_id'))->toBe(['a', 'b'])
        ->and(array_sum(array_column($storyboard['shots'], 'duration')))->toBe(15)
        ->and($planner->fitDurations([20, 1], 30, 3, 10))->toBe([10, 10])
        ->and(fn () => $planner->normalise(['video_title' => 'T', 'shots' => [$shot(5, 5)]], $sources, 10, $limits))
        ->toThrow(ProviderException::class);
});

test('generating clips holds credits per second plus music and stores every clip', function () {
    $project = draftVideo(['soundtrack_mode' => SoundtrackMode::AiMusic]);

    $this->actingAs($this->owner)
        ->post(route('videos.clips.generate', $project))
        ->assertSessionHasNoErrors();

    $project->refresh();
    expect($project->shots->pluck('status')->unique()->all())->toBe(['completed'])
        ->and($project->shots->every(fn (VideoShot $shot) => $shot->output_asset_id !== null))->toBeTrue()
        ->and($project->soundtrack?->asset_type)->toBe(AssetType::Audio)
        ->and($project->status)->toBe(VideoStatus::ReadyToRender)
        ->and($this->credits->wallet($this->workspace->id)->balance)->toBe(100 - 10 - 8 - 3);

    // The panorama is sent as a flat forward view, and every prompt protects the architecture.
    expect($this->videoProvider->requests)->toHaveCount(2)
        ->and($this->videoProvider->requests[1]->imageUrl)->toStartWith('https://storage.test/view/')
        ->and($this->videoProvider->requests[0]->prompt)->toContain(VideoStudioService::PRESERVATION)
        ->and($this->musicProvider->prompts[0])->toContain('Instrumental only')
        // Requested 15 s (shots total 9 s) plus the outro.
        ->and($this->musicProvider->lengths[0])->toBe(18)
        ->and($this->videoProvider->requests[0]->withAudio)->toBeFalse();
});

test('generating clips needs enough credits for all of them', function () {
    Queue::fake();
    $project = draftVideo();
    $this->credits->wallet($this->workspace->id)->forceFill(['balance' => 5])->save();

    $this->actingAs($this->owner)
        ->post(route('videos.clips.generate', $project))
        ->assertSessionHasErrors('credits');

    expect(AiGeneration::count())->toBe(0)
        ->and($project->shots()->where('status', 'generating')->count())->toBe(0);
});

test('a failed clip returns its credits and only that shot is regenerated', function () {
    $project = draftVideo();
    $this->videoProvider->error = new ProviderException('Blocked by safety filters');

    $this->actingAs($this->owner)->post(route('videos.clips.generate', $project));

    expect($project->shots()->where('status', 'failed')->count())->toBe(2)
        ->and($this->credits->available($this->workspace->id))->toBe(100);

    $this->videoProvider->error = null;
    $shot = $project->shots()->orderBy('sort_order')->first();

    $this->post(route('videos.shots.regenerate', [$project, $shot]))->assertSessionHasNoErrors();

    expect($shot->fresh()->status)->toBe('completed')
        ->and($project->shots()->where('status', 'failed')->count())->toBe(1)
        ->and(AiGeneration::where('generation_type', GenerationType::VideoClip)->count())->toBe(3)
        ->and($this->credits->wallet($this->workspace->id)->balance)->toBe(90);
});

test('changing what a shot shows discards its clip, but changing the transition does not', function () {
    $project = draftVideo();
    $this->actingAs($this->owner)->post(route('videos.clips.generate', $project));
    [$first, $second] = $project->shots()->orderBy('sort_order')->get();

    $this->patch(route('videos.shots.update', [$project, $first]), ['transition' => 'cut'])->assertSessionHasNoErrors();
    $this->patch(route('videos.shots.update', [$project, $second]), ['prompt' => 'A wider, slower move across the kitchen island.'])->assertSessionHasNoErrors();

    expect($first->fresh()->status)->toBe('completed')
        ->and($second->fresh()->status)->toBe('pending')
        ->and($second->fresh()->output_asset_id)->toBeNull()
        ->and($project->fresh()->status)->toBe(VideoStatus::Draft);

    $this->patch(route('videos.shots.update', [$project, $first]), ['duration' => 30])->assertSessionHasErrors('duration');
});

test('shots can be reordered with the complete list only', function () {
    $project = draftVideo();
    [$first, $second] = $project->shots()->orderBy('sort_order')->get();

    $this->actingAs($this->owner)
        ->put(route('videos.shots.reorder', $project), ['shot_ids' => [$first->ulid]])
        ->assertSessionHasErrors('shot_ids');

    $this->put(route('videos.shots.reorder', $project), ['shot_ids' => [$second->ulid, $first->ulid]])->assertSessionHasNoErrors();

    expect($project->shots()->orderBy('sort_order')->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('a video renders only when every clip is ready, then becomes the final video', function () {
    Http::fake(['storage.test/*' => fn () => Http::response('media-bytes')]);
    $project = draftVideo(['soundtrack_mode' => SoundtrackMode::AiMusic]);

    $this->actingAs($this->owner)
        ->post(route('videos.render', $project))
        ->assertSessionHasErrors('render');

    $this->post(route('videos.clips.generate', $project));
    $this->post(route('videos.render', $project))->assertRedirect()->assertSessionHasNoErrors();

    $project->refresh();
    $spec = $this->renderer->specs[0];

    expect($project->status)->toBe(VideoStatus::Completed)
        ->and($project->finalAsset?->asset_type)->toBe(AssetType::Video)
        ->and($project->posterAsset)->not->toBeNull()
        ->and($project->exports()->sole()->status)->toBe('completed')
        ->and($spec->clips)->toHaveCount(2)
        ->and($spec->music)->not->toBeNull()
        ->and([$spec->width, $spec->height])->toBe([1280, 720])
        ->and(File::isDirectory($spec->workingDirectory))->toBeFalse();
});

test('a published video can be watched, counted and downloaded at its share link', function () {
    Http::fake(['storage.test/*' => fn () => Http::response('media-bytes')]);
    $project = draftVideo();
    $this->actingAs($this->owner)->post(route('videos.clips.generate', $project));
    $this->post(route('videos.render', $project));
    $project->refresh();
    $link = route('videos.public', ['publicId' => $project->public_id]);

    auth()->logout();
    $this->get($link)->assertNotFound();

    $this->actingAs($this->owner)->post(route('videos.publish', $project), ['visibility' => 'unlisted'])->assertSessionHasNoErrors();
    auth()->logout();

    $this->get($link)
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'frame-ancestors *')
        ->assertInertia(fn (Assert $page) => $page->component('public/VideoPlayer')->where('video.url', $project->finalAsset->secure_url));

    $this->postJson(route('videos.public.events', ['publicId' => $project->public_id]), ['type' => 'play'])->assertNoContent();
    $this->get(route('videos.public.download', ['publicId' => $project->public_id]))->assertRedirect();

    expect($project->fresh())
        ->views_count->toBe(1)
        ->plays_count->toBe(1)
        ->downloads_count->toBe(1);

    $project->update(['visibility' => TourVisibility::Private]);
    $this->get($link)->assertNotFound();
});

test('a video cannot be published before it is rendered', function () {
    $project = draftVideo();

    $this->actingAs($this->owner)
        ->post(route('videos.publish', $project), ['visibility' => 'public'])
        ->assertSessionHasErrors('publish');

    expect($project->fresh()->published_at)->toBeNull();
});

test('viewers can watch the studio but not change it, and other workspaces are kept out', function () {
    Queue::fake();
    $project = draftVideo();
    $shot = $project->shots()->first();

    $viewer = User::factory()->create();
    $this->workspace->addMember($viewer, WorkspaceRole::Viewer);
    $viewer->switchWorkspace($this->workspace);

    $this->actingAs($viewer)
        ->get(route('videos.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('can.update', false));

    $this->post(route('videos.clips.generate', $project))->assertForbidden();
    $this->patch(route('videos.shots.update', [$project, $shot]), ['transition' => 'cut'])->assertForbidden();
    $this->post(route('videos.render', $project))->assertForbidden();
    $this->delete(route('videos.destroy', $project))->assertForbidden();

    $outsider = User::factory()->withWorkspace()->create();
    $this->actingAs($outsider)->get(route('videos.show', $project))->assertForbidden();
    $this->actingAs($outsider)->get(route('videos.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('sources', 0));
});
