<?php

use App\Contracts\Media\MediaStorage;
use App\Enums\AssetType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\WorkspaceRole;
use App\Jobs\Media\DeleteStoredMedia;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\User;
use App\Models\VirtualTour;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeMediaStorage;

beforeEach(function () {
    $this->storage = new FakeMediaStorage;
    $this->app->instance(MediaStorage::class, $this->storage);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->folder = $this->workspace->storageFolder();
});

function registerPayload(string $publicId, array $overrides = []): array
{
    return [
        'public_id' => $publicId,
        'resource_type' => 'image',
        'asset_type' => 'photo',
        'original_filename' => 'living-room.jpg',
        ...$overrides,
    ];
}

test('the upload signature targets the workspace folder', function () {
    $this->actingAs($this->owner)
        ->postJson(route('media.signature'))
        ->assertOk()
        ->assertJsonPath('fields.folder', $this->folder)
        ->assertJsonMissingPath('fields.api_secret');
});

test('uploads are refused with a clear message when storage is not configured', function () {
    $this->storage->configured = false;

    $this->actingAs($this->owner)
        ->postJson(route('media.signature'))
        ->assertServiceUnavailable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'not configured'));
});

test('a verified upload is registered using the storage provider\'s metadata', function () {
    $property = Property::factory()->inWorkspace($this->workspace)->create();
    $publicId = $this->storage->put("{$this->folder}/abc123", format: 'png', width: 3000, height: 2000, bytes: 2_500_000);

    $this->actingAs($this->owner)
        ->postJson(route('media.store'), registerPayload($publicId, [
            'property_id' => $property->ulid,
            'original_filename' => '../../etc/living-room.png',
        ]))
        ->assertCreated()
        ->assertJsonPath('asset.asset_type', 'photo')
        ->assertJsonPath('asset.property.id', $property->ulid);

    expect(MediaAsset::sole())
        ->workspace_id->toBe($this->workspace->id)
        ->uploaded_by->toBe($this->owner->id)
        ->mime_type->toBe('image/png')
        ->file_size->toBe(2_500_000)
        ->width->toBe(3000)
        ->original_filename->toBe('living-room.png')
        ->thumbnail_url->toBe("https://storage.test/thumb/{$publicId}");
});

test('panoramas are inspected and non 2:1 images are flagged, not rejected', function () {
    $good = $this->storage->put("{$this->folder}/pano-good", width: 8000, height: 4000);
    $odd = $this->storage->put("{$this->folder}/pano-odd", width: 6000, height: 4000);

    $this->actingAs($this->owner);
    $this->postJson(route('media.store'), registerPayload($good, ['asset_type' => 'panorama']))->assertCreated();
    $this->postJson(route('media.store'), registerPayload($odd, ['asset_type' => 'panorama']))->assertCreated();

    $assets = MediaAsset::where('asset_type', AssetType::Panorama)->get()->keyBy('cloudinary_public_id');

    expect($assets[$good]->metadata['panorama'])->toMatchArray(['is_equirectangular' => true, 'warnings' => []])
        ->and($assets[$odd]->metadata['panorama']['is_equirectangular'])->toBeFalse()
        ->and($assets[$odd]->metadata['panorama']['warnings'])->not->toBeEmpty();
});

test('audio files are classified as audio regardless of the requested type', function () {
    $publicId = $this->storage->put("{$this->folder}/track", format: 'mp3', resourceType: 'video', width: null, height: null, isAudio: true);

    $this->actingAs($this->owner)
        ->postJson(route('media.store'), registerPayload($publicId, ['resource_type' => 'video', 'asset_type' => 'video']))
        ->assertCreated()
        ->assertJsonPath('asset.asset_type', 'audio')
        ->assertJsonPath('asset.thumbnail_url', null);
});

test('uploads outside the workspace folder are rejected', function () {
    $other = User::factory()->withWorkspace()->create();
    $publicId = $this->storage->put($other->currentWorkspace->storageFolder().'/stolen');

    $this->actingAs($this->owner)
        ->postJson(route('media.store'), registerPayload($publicId))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('public_id');

    expect(MediaAsset::count())->toBe(0);
});

test('uploads missing from storage are rejected', function () {
    $this->actingAs($this->owner)
        ->postJson(route('media.store'), registerPayload("{$this->folder}/never-uploaded"))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('public_id');
});

test('a disallowed format is rejected and removed from storage', function () {
    Queue::fake();
    $publicId = $this->storage->put("{$this->folder}/scan", format: 'heic');

    $this->actingAs($this->owner)
        ->postJson(route('media.store'), registerPayload($publicId, ['asset_type' => 'panorama']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(MediaAsset::count())->toBe(0);
    Queue::assertPushed(DeleteStoredMedia::class, fn (DeleteStoredMedia $job) => $job->publicId === $publicId);
});

test('an upload cannot be assigned to another workspace\'s property', function () {
    $foreignProperty = Property::factory()->create();
    $publicId = $this->storage->put("{$this->folder}/photo");

    $this->actingAs($this->owner)
        ->postJson(route('media.store'), registerPayload($publicId, ['property_id' => $foreignProperty->ulid]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('property_id');
});

test('the same upload cannot be registered twice', function () {
    $publicId = $this->storage->put("{$this->folder}/photo");

    $this->actingAs($this->owner);
    $this->postJson(route('media.store'), registerPayload($publicId))->assertCreated();
    $this->postJson(route('media.store'), registerPayload($publicId))->assertUnprocessable();

    expect(MediaAsset::count())->toBe(1);
});

test('viewers cannot upload', function () {
    $viewer = User::factory()->create();
    $this->workspace->addMember($viewer, WorkspaceRole::Viewer);
    $viewer->switchWorkspace($this->workspace);

    $this->actingAs($viewer)->postJson(route('media.signature'))->assertForbidden();
    $this->actingAs($viewer)->postJson(route('media.store'), registerPayload("{$this->folder}/x"))->assertForbidden();
});

test('the library lists and filters the current workspace\'s media', function () {
    MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'original_filename' => 'kitchen.jpg']);
    MediaAsset::factory()->panorama()->create(['workspace_id' => $this->workspace->id, 'original_filename' => 'lounge-360.jpg']);
    MediaAsset::factory()->create(['original_filename' => 'not-mine.jpg']);

    $this->actingAs($this->owner)
        ->get(route('media.index'))
        ->assertInertia(fn (Assert $page) => $page->component('media/Index')->has('assets.data', 2));

    $this->actingAs($this->owner)
        ->get(route('media.index', ['type' => 'panorama']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('assets.data', 1)
            ->where('assets.data.0.original_filename', 'lounge-360.jpg'));
});

test('reclassifying a photo as a panorama runs the panorama inspection', function () {
    $asset = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'width' => 8000, 'height' => 4000]);

    $this->actingAs($this->owner)
        ->patch(route('media.update', $asset), ['asset_type' => 'panorama'])
        ->assertSessionHasNoErrors();

    expect($asset->fresh())
        ->asset_type->toBe(AssetType::Panorama)
        ->metadata->toHaveKey('panorama.is_equirectangular', true);
});

test('an image cannot be reclassified as video or audio', function () {
    $asset = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->patch(route('media.update', $asset), ['asset_type' => 'video'])
        ->assertSessionHasErrors('asset_type');
});

test('deleting media removes the record and queues removal from storage', function () {
    Queue::fake();
    $asset = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)->delete(route('media.destroy', $asset))->assertRedirect();

    expect(MediaAsset::count())->toBe(0);
    Queue::assertPushed(DeleteStoredMedia::class, fn (DeleteStoredMedia $job) => $job->publicId === $asset->cloudinary_public_id);
});

test('editors can delete their own uploads but not other people\'s', function () {
    $editor = User::factory()->create();
    $this->workspace->addMember($editor, WorkspaceRole::Editor);
    $editor->switchWorkspace($this->workspace);

    $theirs = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'uploaded_by' => $editor->id]);
    $owners = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'uploaded_by' => $this->owner->id]);

    $this->actingAs($editor)->delete(route('media.destroy', $owners))->assertForbidden();
    $this->actingAs($editor)->delete(route('media.destroy', $theirs))->assertRedirect();
});

test('other workspaces cannot delete media', function () {
    $asset = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    $outsider = User::factory()->withWorkspace()->create();

    $this->actingAs($outsider)->delete(route('media.destroy', $asset))->assertForbidden();

    expect($asset->fresh())->not->toBeNull();
});

test('several files can be deleted at once, keeping panoramas used in a tour', function () {
    Queue::fake();
    $free = MediaAsset::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);
    $used = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'asset_type' => 'panorama']);
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    $tour->scenes()->create(['media_asset_id' => $used->id, 'title' => 'Hall', 'sort_order' => 1]);
    $foreign = MediaAsset::factory()->create();

    $this->actingAs($this->owner)
        ->delete(route('media.destroy-many'), ['ids' => [...$free->pluck('ulid')->all(), $used->ulid, $foreign->ulid]])
        ->assertRedirect();

    expect(MediaAsset::whereIn('id', $free->pluck('id'))->count())->toBe(0)
        ->and($used->fresh())->not->toBeNull()
        ->and($foreign->fresh())->not->toBeNull();
});

test('a photo being enhanced is flagged so the page can show progress', function () {
    $photo = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'asset_type' => AssetType::Photo]);
    $generation = new AiGeneration([
        'user_id' => $this->owner->id,
        'generation_type' => GenerationType::PhotoEnhance,
        'provider' => 'openai',
        'model' => 'x',
        'status' => GenerationStatus::Queued,
        'input_payload' => ['source_asset_id' => $photo->ulid],
    ]);
    $generation->workspace_id = $this->workspace->id;
    $generation->save();

    $this->actingAs($this->owner)
        ->get(route('media.index'))
        ->assertInertia(fn (Assert $page) => $page->where('assets.data.0.enhancement_id', $generation->ulid));
});

test('the picker search reaches files beyond the latest ones, by name, in this workspace only', function () {
    MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'asset_type' => AssetType::Photo, 'original_filename' => 'kitchen-island.jpg']);
    MediaAsset::factory()->count(3)->create(['workspace_id' => $this->workspace->id, 'asset_type' => AssetType::Photo, 'original_filename' => 'bedroom.jpg']);
    MediaAsset::factory()->create(['asset_type' => AssetType::Photo, 'original_filename' => 'kitchen-other.jpg']);

    $this->actingAs($this->owner)
        ->getJson(route('media.pick', ['type' => 'photo', 'q' => 'kitchen']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'kitchen-island.jpg')
        ->assertJsonPath('has_more', false);

    $this->getJson(route('media.pick', ['type' => 'photo']))->assertJsonCount(4, 'data');
    $this->getJson(route('media.pick', ['type' => 'nonsense']))->assertUnprocessable();
});
