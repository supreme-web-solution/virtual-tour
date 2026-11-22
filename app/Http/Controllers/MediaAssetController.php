<?php

namespace App\Http\Controllers;

use App\Contracts\Media\MediaStorage;
use App\Enums\AssetType;
use App\Enums\Entitlement;
use App\Http\Requests\Media\RegisterMediaRequest;
use App\Http\Requests\Media\UpdateMediaRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Billing\EntitlementService;
use App\Services\Media\MediaLibraryService;
use App\Services\Panorama\PanoramaValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MediaAssetController extends Controller
{
    public function __construct(
        protected MediaStorage $storage,
        protected MediaLibraryService $library,
    ) {}

    /**
     * The workspace media library.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MediaAsset::class);

        $user = $this->user($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(AssetType::class)],
            'property' => ['nullable', 'string', 'max:40'],
        ]);

        $assets = MediaAsset::query()
            ->where('workspace_id', $user->current_workspace_id)
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('asset_type', $type))
            ->when($filters['property'] ?? null, fn ($query, string $property) => $property === 'none'
                ? $query->whereNull('property_id')
                : $query->whereHas('property', fn ($query) => $query->where('ulid', $property)))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(
                'original_filename',
                'like',
                '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%',
            ))
            ->with(['property', 'uploader'])
            ->latest()
            ->paginate(36)
            ->withQueryString()
            ->through(fn (MediaAsset $asset): array => MediaAssetResource::make($asset)->resolve($request));

        return Inertia::render('media/Index', [
            'assets' => $assets,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? '',
                'property' => $filters['property'] ?? '',
            ],
            'properties' => $this->propertyOptions($user),
            'assetTypes' => AssetType::options(),
            'can' => ['upload' => $user->can('create', MediaAsset::class)],
        ]);
    }

    /**
     * Sign a direct browser upload into the current workspace's folder.
     */
    public function signature(Request $request): JsonResponse
    {
        Gate::authorize('create', MediaAsset::class);

        if (! $this->storage->isConfigured()) {
            return response()->json([
                'message' => __('Media storage is not configured. An administrator must add the Cloudinary credentials.'),
            ], 503);
        }

        $workspace = $this->user($request)->currentWorkspace;
        app(EntitlementService::class)->ensureCanAdd($workspace->id, Entitlement::StorageMb, 1);

        return response()->json($this->storage->signUpload($workspace->storageFolder())->toArray());
    }

    /**
     * Register an uploaded file in the library after verifying it with the storage provider.
     */
    public function store(RegisterMediaRequest $request): JsonResponse
    {
        Gate::authorize('create', MediaAsset::class);

        $user = $this->user($request);

        $asset = $this->library->registerUpload(
            workspace: $user->currentWorkspace,
            user: $user,
            publicId: $request->string('public_id')->toString(),
            resourceType: $request->string('resource_type')->toString(),
            assetType: $request->enum('asset_type', AssetType::class),
            propertyId: $request->propertyId(),
            originalFilename: $request->input('original_filename'),
        );

        $asset->load(['property', 'uploader']);

        return response()->json(['asset' => MediaAssetResource::make($asset)->resolve($request)], 201);
    }

    /**
     * Reassign an asset to another property, or correct its type.
     */
    public function update(UpdateMediaRequest $request, MediaAsset $media, PanoramaValidationService $panoramas, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('update', $media);

        $media->property_id = $request->propertyId();
        $media->original_filename = $request->validated('original_filename') ?? $media->original_filename;

        $type = $request->enum('asset_type', AssetType::class);

        if ($type !== null && $type !== $media->asset_type) {
            $media->asset_type = $type;
            $metadata = $media->metadata ?? [];

            if ($type === AssetType::Panorama) {
                $metadata['panorama'] = $panoramas->inspect($media->width, $media->height);
            } else {
                unset($metadata['panorama']);
            }

            $media->metadata = $metadata === [] ? null : $metadata;
        }

        $media->save();

        $activity->log($media->workspace_id, $request->user(), 'media.updated', "Updated {$media->asset_type->label()} {$media->original_filename}", $media);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Media updated.')]);

        return back();
    }

    public function destroy(Request $request, MediaAsset $media): RedirectResponse
    {
        Gate::authorize('delete', $media);

        $sceneCount = $media->tourScenes()->count();

        if ($sceneCount > 0) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => trans_choice('This panorama is used by :count tour scene. Remove it from the tour first.|This panorama is used by :count tour scenes. Remove them from their tours first.', $sceneCount),
            ]);

            return back();
        }

        $this->library->delete($media, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Media deleted.')]);

        return back();
    }

    /**
     * Search the whole workspace library by file name, a page at a time, for
     * the pickers (new tour, add scenes) that only preload the latest files.
     */
    public function pick(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::authorize('viewAny', MediaAsset::class);

        $data = $request->validate([
            'type' => ['required', Rule::enum(AssetType::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = MediaAsset::query()
            ->where('workspace_id', $user->current_workspace_id)
            ->where('asset_type', $data['type'])
            ->when(filled($data['q'] ?? null), fn ($query) => $query->where('original_filename', 'like', '%'.addcslashes((string) $data['q'], '%_\\').'%'))
            ->with('property:id,name')
            ->latest('id')
            ->simplePaginate(48, page: (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (MediaAsset $asset): array => [
                'id' => $asset->ulid,
                'name' => $asset->original_filename,
                'filename' => $asset->original_filename,
                'thumbnail_url' => $asset->thumbnail_url,
                'width' => $asset->width,
                'height' => $asset->height,
                'is_equirectangular' => (bool) ($asset->metadata['panorama']['is_equirectangular'] ?? false),
                'property' => $asset->property?->name,
            ])->values(),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    /**
     * Delete several files at once. Files used by a tour scene, or that the
     * user may not delete, are skipped and reported.
     */
    public function destroyMany(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'distinct'],
        ]);

        $assets = MediaAsset::query()
            ->where('workspace_id', $user->current_workspace_id)
            ->whereIn('ulid', $data['ids'])
            ->get();

        $deleted = 0;
        $skipped = 0;

        foreach ($assets as $asset) {
            if (! $user->can('delete', $asset) || $asset->tourScenes()->exists()) {
                $skipped++;

                continue;
            }

            $this->library->delete($asset, $user);
            $deleted++;
        }

        $message = trans_choice('Deleted :count file.|Deleted :count files.', $deleted);

        if ($skipped > 0) {
            $message .= ' '.trans_choice(':count file is used in a tour or cannot be deleted by you, so it was kept.|:count files are used in a tour or cannot be deleted by you, so they were kept.', $skipped);
        }

        Inertia::flash('toast', ['type' => $deleted > 0 ? 'success' : 'error', 'message' => $message]);

        return back();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    protected function propertyOptions(User $user): array
    {
        return array_values(Property::query()
            ->where('workspace_id', $user->current_workspace_id)
            ->orderBy('name')
            ->get(['ulid', 'name'])
            ->map(fn (Property $property): array => ['id' => $property->ulid, 'name' => $property->name])
            ->all());
    }

    protected function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
