<?php

namespace App\Http\Controllers;

use App\Contracts\AI\StructuredOutputProvider;
use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Enums\AssetType;
use App\Enums\PropertyType;
use App\Http\Requests\Imports\CompleteImportRequest;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\PropertyImport;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\Credits\CreditService;
use App\Services\Tours\TourBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The "Create tour" dialog: four ways in, one tour. Everything answers JSON
 * so the dialog can stay open and show progress.
 */
class TourCreateController extends Controller
{
    public function __construct(protected TourBuilder $builder) {}

    /**
     * What the dialog needs: which ways are available, prices, credits and
     * recent media to pick from.
     */
    public function options(Request $request, StructuredOutputProvider $ai, PanoramaGenerationProvider $panoramas, CreditService $credits): JsonResponse
    {
        Gate::authorize('create', VirtualTour::class);
        $workspaceId = (int) $this->user($request)->current_workspace_id;

        $recent = fn (AssetType $type): array => array_values(MediaAsset::where('workspace_id', $workspaceId)
            ->where('asset_type', $type)
            ->latest('id')
            ->limit(24)
            ->get(['ulid', 'original_filename', 'thumbnail_url', 'width', 'height'])
            ->map(fn (MediaAsset $asset): array => [
                'id' => $asset->ulid,
                'name' => $asset->original_filename,
                'thumbnail_url' => $asset->thumbnail_url,
                'width' => $asset->width,
                'height' => $asset->height,
            ])->all());

        return response()->json([
            'features' => [
                'ai' => $ai->isConfigured(),
                'panoramas' => $panoramas->isConfigured(),
            ],
            'prices' => $this->builder->prices(),
            'available' => $credits->available($workspaceId),
            'max_scenes' => (int) config('ai.limits.max_scenes_per_plan', 12),
            'max_photos' => (int) config('ai.providers.openai.max_photos_per_analysis', 20),
            'property_types' => PropertyType::options(),
            'asset_types' => AssetType::options(),
            'properties' => Property::where('workspace_id', $workspaceId)->orderBy('name')->get(['ulid', 'name'])
                ->map(fn (Property $property): array => ['value' => $property->ulid, 'label' => $property->name])->values(),
            'recent' => ['photos' => $recent(AssetType::Photo), 'panoramas' => $recent(AssetType::Panorama)],
        ]);
    }

    public function describe(Request $request): JsonResponse
    {
        Gate::authorize('create', VirtualTour::class);
        $user = $this->user($request);

        $data = $request->validate([
            'description' => ['required', 'string', 'min:15', 'max:2000'],
            'scene_count' => ['required', 'integer', 'between:2,'.config('ai.limits.max_scenes_per_plan', 12)],
            'property_type' => ['nullable', Rule::enum(PropertyType::class)],
            'property_id' => $this->propertyRule($user),
        ]);

        $tour = $this->builder->describe($user, [
            'description' => (string) $data['description'],
            'scene_count' => (int) $data['scene_count'],
            'property_type' => $data['property_type'] ?? null,
            'property_id' => $this->propertyId($data['property_id'] ?? null),
        ]);

        return $this->started($tour);
    }

    public function photos(Request $request): JsonResponse
    {
        Gate::authorize('create', VirtualTour::class);
        $user = $this->user($request);

        $data = $request->validate([
            'photo_ids' => ['required', 'array', 'min:1', 'max:'.config('ai.providers.openai.max_photos_per_analysis', 20)],
            'photo_ids.*' => ['string', 'distinct', $this->assetRule($user, AssetType::Photo)],
            'property_id' => $this->propertyRule($user),
            'title' => ['nullable', 'string', 'max:160'],
            'convert' => ['nullable', 'boolean'],
        ]);

        $tour = $this->builder->photos($user, $this->assets($data['photo_ids']), $this->propertyId($data['property_id'] ?? null), $data['title'] ?? null, (bool) ($data['convert'] ?? true));

        return $this->started($tour);
    }

    public function panoramas(Request $request): JsonResponse
    {
        Gate::authorize('create', VirtualTour::class);
        $user = $this->user($request);

        $data = $request->validate([
            'panorama_ids' => ['required', 'array', 'min:1', 'max:50'],
            'panorama_ids.*' => ['string', 'distinct', $this->assetRule($user, AssetType::Panorama)],
            'property_id' => $this->propertyRule($user),
            'title' => ['nullable', 'string', 'max:160'],
        ]);

        $tour = $this->builder->panoramas($user, $this->assets($data['panorama_ids']), $this->propertyId($data['property_id'] ?? null), $data['title'] ?? null);

        return $this->started($tour);
    }

    /**
     * Finish a reviewed listing import as a tour.
     */
    public function link(CompleteImportRequest $request, PropertyImport $import): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($import->workspace_id === $user->current_workspace_id, 404);
        Gate::authorize('create', VirtualTour::class);

        $tour = $this->builder->fromImport($user, $import, $request->details(), array_values((array) $request->validated('image_urls', [])));

        return $this->started($tour);
    }

    public function progress(Request $request, VirtualTour $tour): JsonResponse
    {
        Gate::authorize('view', $tour);

        return response()->json(['tour' => [
            ...$this->builder->status($tour),
            'editor_url' => route('tours.edit', $tour),
        ]]);
    }

    protected function started(VirtualTour $tour): JsonResponse
    {
        // Queued steps may already have moved the build on (sync queues, fast jobs).
        $tour->refresh();

        return response()->json(['tour' => [
            ...$this->builder->status($tour),
            'editor_url' => route('tours.edit', $tour),
        ]], 201);
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<int, MediaAsset>
     */
    protected function assets(array $ids): Collection
    {
        $positions = array_flip($ids);

        return MediaAsset::whereIn('ulid', $ids)->get()
            ->sortBy(fn (MediaAsset $asset): int => $positions[$asset->ulid] ?? PHP_INT_MAX)
            ->values();
    }

    protected function assetRule(User $user, AssetType $type): mixed
    {
        return Rule::exists(MediaAsset::class, 'ulid')->where('workspace_id', $user->current_workspace_id)->where('asset_type', $type->value);
    }

    /**
     * @return list<mixed>
     */
    protected function propertyRule(User $user): array
    {
        return ['nullable', 'string', Rule::exists(Property::class, 'ulid')->where('workspace_id', $user->current_workspace_id)->whereNull('deleted_at')];
    }

    protected function propertyId(?string $ulid): ?int
    {
        return $ulid ? Property::where('ulid', $ulid)->value('id') : null;
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
