<?php

namespace App\Http\Controllers\AI;

use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Enums\AssetType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\PropertyType;
use App\Exceptions\ProviderException;
use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\AI\GenerationPresenter;
use App\Services\Credits\CreditService;
use App\Services\Panorama\PanoramaStyle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AiStudioController extends Controller
{
    /**
     * The AI Studio: start generations and follow their progress.
     */
    public function index(Request $request, CreditService $credits, GenerationPresenter $presenter): Response
    {
        Gate::authorize('viewAny', AiGeneration::class);

        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->current_workspace_id;
        $wallet = $credits->wallet($workspaceId);

        $generations = AiGeneration::where('workspace_id', $workspaceId)
            ->with('tour')
            ->latest()
            ->paginate(20);

        return Inertia::render('ai-studio/Index', [
            'credits' => ['available' => $wallet->available(), 'reserved' => $wallet->reserved, 'balance' => $wallet->balance],
            'prices' => collect(GenerationType::cases())->mapWithKeys(fn (GenerationType $type): array => [$type->value => $type->credits()]),
            'generations' => $generations->through(fn (AiGeneration $generation): array => $presenter->summary($generation, $user)),
            'hasActive' => AiGeneration::where('workspace_id', $workspaceId)->whereIn('status', GenerationStatus::activeValues())->exists(),
            'propertyTypes' => PropertyType::options(),
            'properties' => $this->properties($workspaceId),
            'tours' => VirtualTour::where('workspace_id', $workspaceId)->latest('updated_at')->limit(100)->get(['ulid', 'title'])
                ->map(fn (VirtualTour $tour): array => ['id' => $tour->ulid, 'title' => $tour->title])->values(),
            'photos' => MediaAsset::where('workspace_id', $workspaceId)
                ->where('asset_type', AssetType::Photo)
                ->with('property:id,ulid,name')
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (MediaAsset $photo): array => [
                    'id' => $photo->ulid,
                    'filename' => $photo->original_filename,
                    'thumbnail_url' => $photo->thumbnail_url,
                    'property_id' => $photo->property?->ulid,
                    'property' => $photo->property?->name,
                ])->values(),
            'limits' => [
                'max_scenes' => (int) config('ai.limits.max_scenes_per_plan'),
                'max_photos_per_analysis' => (int) config('ai.providers.openai.max_photos_per_analysis'),
                'max_description_length' => (int) config('ai.limits.max_description_length'),
            ],
            'can' => ['create' => $user->can('create', AiGeneration::class)],
        ]);
    }

    /**
     * Panorama styles available on the provider account (cached).
     */
    public function styles(Request $request, PanoramaGenerationProvider $provider): JsonResponse
    {
        Gate::authorize('viewAny', AiGeneration::class);

        if (! $provider->isConfigured()) {
            return response()->json(['message' => 'Skybox is not configured. An administrator must add SKYBOX_API_KEY.', 'styles' => []], 503);
        }

        try {
            $styles = $provider->styles($request->boolean('image_to_panorama'));
        } catch (ProviderException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'styles' => []], 502);
        }

        return response()->json([
            'styles' => array_map(fn (PanoramaStyle $style): array => $style->toArray(), $styles),
            'default_style_id' => config('ai.providers.skybox.default_style_id') !== null ? (int) config('ai.providers.skybox.default_style_id') : null,
        ]);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    protected function properties(int $workspaceId): array
    {
        return array_values(Property::where('workspace_id', $workspaceId)->orderBy('name')->get(['ulid', 'name'])
            ->map(fn (Property $property): array => ['id' => $property->ulid, 'name' => $property->name])
            ->all());
    }
}
