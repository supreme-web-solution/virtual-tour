<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Enums\Entitlement;
use App\Enums\TourVisibility;
use App\Http\Requests\Tours\TourRequest;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\ActivityLogger;
use App\Services\Billing\EntitlementService;
use App\Services\CommentPresenter;
use App\Services\Tours\TourPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class VirtualTourController extends Controller
{
    public function __construct(
        protected TourPresenter $presenter,
        protected ActivityLogger $activity,
    ) {}

    /**
     * List the current workspace's tours.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', VirtualTour::class);

        /** @var User $user */
        $user = $request->user();

        $search = trim($request->string('q')->toString());

        $tours = VirtualTour::query()
            ->where('workspace_id', $user->current_workspace_id)
            ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.$search.'%'))
            ->with(['property', 'coverAsset', 'scenes' => fn ($query) => $query->with('mediaAsset')->limit(1)])
            ->withCount('scenes')
            ->latest('updated_at')
            ->paginate(12)
            ->through(fn (VirtualTour $tour): array => $this->presenter->listItem($tour, $user));

        return Inertia::render('tours/Index', [
            'tours' => $tours,
            'filters' => ['q' => $search],
            'properties' => $this->propertyOptions($user->current_workspace_id),
            'defaultPropertyId' => $request->string('property')->toString() ?: null,
            'can' => ['create' => $user->can('create', VirtualTour::class)],
        ]);
    }

    public function store(TourRequest $request, EntitlementService $entitlements): RedirectResponse
    {
        Gate::authorize('create', VirtualTour::class);

        /** @var User $user */
        $user = $request->user();
        $entitlements->ensureCanAdd((int) $user->current_workspace_id, Entitlement::Tours);

        $tour = new VirtualTour([
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            'visibility' => TourVisibility::Unlisted,
        ]);
        $tour->property_id = $request->propertyId();
        $tour->workspace_id = (int) $user->current_workspace_id;
        $tour->created_by = $user->id;
        $tour->save();

        $this->activity->log($tour->workspace_id, $user, 'tour.created', "Created tour {$tour->title}", $tour);

        return to_route('tours.edit', $tour);
    }

    /**
     * The visual tour editor.
     */
    public function edit(Request $request, VirtualTour $tour): Response
    {
        Gate::authorize('view', $tour);

        return Inertia::render('tours/Editor', [
            'comments' => fn (): array => app(CommentPresenter::class)->for($tour, $request->user()),
            'tour' => $this->presenter->forEditor($tour),
            'panoramas' => $this->panoramaLibrary($tour),
            'library' => [
                'images' => $this->assetOptions($tour, [AssetType::Logo, AssetType::Photo]),
                'audio' => $this->assetOptions($tour, [AssetType::Audio]),
                'floorplans' => $this->assetOptions($tour, [AssetType::FloorPlan]),
                // Images and videos a media hotspot can show.
                'media' => $this->assetOptions($tour, [AssetType::Photo, AssetType::Video]),
            ],
            'properties' => $this->propertyOptions($tour->workspace_id),
            'assetTypes' => AssetType::options(),
            'visibilityOptions' => TourVisibility::options(),
            'can' => [
                'update' => $request->user()?->can('update', $tour) ?? false,
                'publish' => $request->user()?->can('publish', $tour) ?? false,
                'delete' => $request->user()?->can('delete', $tour) ?? false,
                'upload' => $request->user()?->can('create', MediaAsset::class) ?? false,
            ],
        ]);
    }

    /**
     * Save tour settings from the editor (partial updates).
     */
    public function update(TourRequest $request, VirtualTour $tour, EntitlementService $entitlements): JsonResponse
    {
        Gate::authorize('update', $tour);

        $data = $request->validated();

        if (($data['branding']['show_platform_branding'] ?? true) === false) {
            $entitlements->ensureAllowed($tour->workspace_id, Entitlement::RemoveBranding);
        }

        $tour->fill(collect($data)->only(['title', 'description', 'visibility'])->all());

        if (array_key_exists('property_id', $data)) {
            $tour->property_id = $request->propertyId();
        }

        if (array_key_exists('cover_asset_id', $data)) {
            $tour->cover_asset_id = $request->coverAssetId();
        }

        if (isset($data['viewer_settings'])) {
            $settings = array_merge($tour->viewer_settings ?? [], $data['viewer_settings']);

            if (! empty($settings['start_scene_id']) && ! $tour->scenes()->where('ulid', $settings['start_scene_id'])->exists()) {
                $settings['start_scene_id'] = null;
            }

            $tour->viewer_settings = $settings;
        }

        if (array_key_exists('allowed_embed_domains', $data)) {
            $tour->privacy_settings = array_merge($tour->privacy_settings ?? [], [
                'allowed_embed_domains' => array_values(array_unique(array_map('strtolower', $data['allowed_embed_domains']))),
            ]);
        }

        if (array_key_exists('analytics_enabled', $data)) {
            $tour->privacy_settings = array_merge($tour->privacy_settings ?? [], ['analytics_enabled' => (bool) $data['analytics_enabled']]);
        }

        // Settings groups are merged so the editor can save one field at a time.
        foreach (['branding' => 'branding_settings', 'audio_settings' => 'audio_settings', 'lead_settings' => 'lead_settings', 'map_settings' => 'map_settings'] as $input => $column) {
            if (isset($data[$input])) {
                $tour->{$column} = array_replace_recursive($tour->{$column} ?? [], $data[$input]);
            }
        }

        $tour->save();

        return response()->json(['tour' => $this->presenter->forEditor($tour->fresh())]);
    }

    public function destroy(Request $request, VirtualTour $tour): RedirectResponse
    {
        Gate::authorize('delete', $tour);

        $tour->delete();

        $this->activity->log($tour->workspace_id, $request->user(), 'tour.deleted', "Deleted tour {$tour->title}", $tour);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tour deleted.')]);

        return to_route('tours.index');
    }

    /**
     * Panoramas in the workspace that can be added as scenes.
     *
     * @return list<array<string, mixed>>
     */
    protected function panoramaLibrary(VirtualTour $tour): array
    {
        return array_values(MediaAsset::query()
            ->where('workspace_id', $tour->workspace_id)
            ->where('asset_type', AssetType::Panorama)
            ->with('property:id,name')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (MediaAsset $asset): array => [
                'id' => $asset->ulid,
                'filename' => $asset->original_filename,
                'thumbnail_url' => $asset->thumbnail_url,
                'width' => $asset->width,
                'height' => $asset->height,
                'is_equirectangular' => $asset->isEquirectangular(),
                'property' => $asset->property?->name,
                'from_tour_property' => $tour->property_id !== null && $asset->property_id === $tour->property_id,
            ])
            ->all());
    }

    /**
     * Workspace media of the given types, for pickers in the editor.
     *
     * @param  list<AssetType>  $types
     * @return list<array{id: string, name: string|null, type: string, thumbnail_url: string|null, url: string}>
     */
    protected function assetOptions(VirtualTour $tour, array $types): array
    {
        return array_values(MediaAsset::query()
            ->where('workspace_id', $tour->workspace_id)
            ->whereIn('asset_type', $types)
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (MediaAsset $asset): array => [
                'id' => $asset->ulid,
                'name' => $asset->original_filename,
                'type' => $asset->asset_type->value,
                'thumbnail_url' => $asset->thumbnail_url,
                'url' => $asset->secure_url,
            ])
            ->all());
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    protected function propertyOptions(?int $workspaceId): array
    {
        return array_values(Property::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->get(['ulid', 'name'])
            ->map(fn (Property $property): array => ['id' => $property->ulid, 'name' => $property->name])
            ->all());
    }
}
