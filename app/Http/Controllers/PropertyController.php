<?php

namespace App\Http\Controllers;

use App\Enums\AreaUnit;
use App\Enums\AssetType;
use App\Enums\Entitlement;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Http\Requests\Properties\PropertyRequest;
use App\Http\Resources\MediaAssetResource;
use App\Http\Resources\PropertyResource;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\ActivityLogger;
use App\Services\Billing\EntitlementService;
use App\Services\CommentPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PropertyController extends Controller
{
    public function __construct(protected ActivityLogger $activity) {}

    /**
     * List the current workspace's properties with search and filters.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Property::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(PropertyType::class)],
            'status' => ['nullable', Rule::enum(PropertyStatus::class)],
            'sort' => ['nullable', Rule::in(['latest', 'oldest', 'name', 'updated'])],
        ]);

        $properties = Property::query()
            ->where('workspace_id', $this->user($request)->current_workspace_id)
            ->search($filters['search'] ?? null)
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('property_type', $type))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->with('coverAsset')
            ->withCount('mediaAssets')
            ->tap(fn ($query) => match ($filters['sort'] ?? 'latest') {
                'oldest' => $query->oldest(),
                'name' => $query->orderBy('name'),
                'updated' => $query->latest('updated_at'),
                default => $query->latest(),
            })
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Property $property): array => PropertyResource::make($property)->resolve($request));

        return Inertia::render('properties/Index', [
            'properties' => $properties,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? '',
                'status' => $filters['status'] ?? '',
                'sort' => $filters['sort'] ?? 'latest',
            ],
            'options' => $this->options(),
            'can' => ['create' => $request->user()?->can('create', Property::class) ?? false],
        ]);
    }

    /**
     * The form is a dialog on the properties list; old links open it there.
     */
    public function create(): RedirectResponse
    {
        Gate::authorize('create', Property::class);

        return to_route('properties.index', ['create' => 1]);
    }

    public function store(PropertyRequest $request, EntitlementService $entitlements): RedirectResponse
    {
        Gate::authorize('create', Property::class);

        $user = $this->user($request);
        $entitlements->ensureCanAdd((int) $user->current_workspace_id, Entitlement::Properties);

        $property = new Property($request->propertyAttributes());
        $property->workspace_id = (int) $user->current_workspace_id;
        $property->created_by = $user->id;
        $property->save();

        $this->activity->log($property->workspace_id, $user, 'property.created', "Created property {$property->name}", $property);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property created.')]);

        return to_route('properties.show', $property);
    }

    /**
     * Show a property with its media.
     */
    public function show(Request $request, Property $property): Response
    {
        Gate::authorize('view', $property);

        $property->load('coverAsset')->loadCount('mediaAssets');

        $media = $property->mediaAssets()
            ->with('uploader')
            ->latest()
            ->limit(48)
            ->get();

        $countsByType = $property->mediaAssets()
            ->select('asset_type', DB::raw('count(*) as total'))
            ->groupBy('asset_type')
            ->pluck('total', 'asset_type');

        return Inertia::render('properties/Show', [
            'comments' => fn (): array => app(CommentPresenter::class)->for($property, $this->user($request)),
            'property' => PropertyResource::make($property)->resolve($request),
            'media' => $media->map(fn (MediaAsset $asset): array => MediaAssetResource::make($asset)->resolve($request)),
            'mediaCounts' => collect(AssetType::cases())
                ->mapWithKeys(fn (AssetType $type): array => [$type->value => (int) ($countsByType[$type->value] ?? 0)]),
            'tours' => $property->tours()
                ->withCount('scenes')
                ->latest('updated_at')
                ->get()
                ->map(fn (VirtualTour $tour): array => [
                    'id' => $tour->ulid,
                    'title' => $tour->title,
                    'status' => $tour->status->value,
                    'scenes_count' => $tour->scenes_count,
                ]),
            'assetTypes' => AssetType::options(),
            // For the edit dialog.
            'options' => $this->options(),
            'coverChoices' => fn (): array => array_values($property->mediaAssets()
                ->where('cloudinary_resource_type', 'image')
                ->latest()
                ->limit(60)
                ->get(['ulid', 'thumbnail_url', 'original_filename', 'asset_type'])
                ->map(fn (MediaAsset $asset): array => [
                    'id' => $asset->ulid,
                    'thumbnail_url' => $asset->thumbnail_url,
                    'name' => $asset->original_filename,
                    'asset_type' => $asset->asset_type->value,
                ])->all()),
            'properties' => Property::query()
                ->where('workspace_id', $property->workspace_id)
                ->orderBy('name')
                ->get(['ulid', 'name'])
                ->map(fn (Property $item): array => ['id' => $item->ulid, 'name' => $item->name]),
            'can' => [
                'duplicate' => $request->user()?->can('duplicate', $property) ?? false,
                'upload' => $request->user()?->can('create', MediaAsset::class) ?? false,
                'createTour' => $request->user()?->can('create', VirtualTour::class) ?? false,
            ],
        ]);
    }

    /**
     * The form is a dialog on the property page; old links open it there.
     */
    public function edit(Property $property): RedirectResponse
    {
        Gate::authorize('update', $property);

        return to_route('properties.show', ['property' => $property, 'edit' => 1]);
    }

    public function update(PropertyRequest $request, Property $property): RedirectResponse
    {
        Gate::authorize('update', $property);

        $property->fill($request->propertyAttributes());

        if ($property->isDirty('name')) {
            $property->slug = Property::uniqueSlugFor($property->workspace_id, $property->name, $property->id);
        }

        $property->save();

        $this->activity->log($property->workspace_id, $request->user(), 'property.updated', "Updated property {$property->name}", $property);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property saved.')]);

        return to_route('properties.show', $property);
    }

    /**
     * Copy a property's details into a new draft. Media stays with the original.
     */
    public function duplicate(Request $request, Property $property): RedirectResponse
    {
        Gate::authorize('duplicate', $property);

        $copy = $property->replicate(['ulid', 'slug', 'created_by']);
        $copy->name = mb_substr("{$property->name} (copy)", 0, 160);
        $copy->status = PropertyStatus::Draft;
        $copy->created_by = $request->user()?->id;
        $copy->save();

        $this->activity->log($copy->workspace_id, $request->user(), 'property.duplicated', "Duplicated property {$property->name}", $copy, [
            'source_property' => $property->ulid,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property duplicated.')]);

        return to_route('properties.edit', $copy);
    }

    /**
     * Soft-delete a property. Its media is kept in the library, unassigned.
     */
    public function destroy(Request $request, Property $property): RedirectResponse
    {
        Gate::authorize('delete', $property);

        DB::transaction(function () use ($property): void {
            $property->mediaAssets()->update(['property_id' => null]);
            $property->delete();
        });

        $this->activity->log($property->workspace_id, $request->user(), 'property.deleted', "Deleted property {$property->name}", $property);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property deleted.')]);

        return to_route('properties.index');
    }

    /**
     * @return array{types: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>, area_units: list<string>}
     */
    protected function options(): array
    {
        return [
            'types' => PropertyType::options(),
            'statuses' => PropertyStatus::options(),
            'area_units' => array_map(fn (AreaUnit $unit): string => $unit->value, AreaUnit::cases()),
        ];
    }

    protected function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
