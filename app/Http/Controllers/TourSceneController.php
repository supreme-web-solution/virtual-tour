<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Enums\Entitlement;
use App\Http\Requests\Tours\SceneRequest;
use App\Models\MediaAsset;
use App\Models\TourScene;
use App\Models\VirtualTour;
use App\Services\Billing\EntitlementService;
use App\Services\Panorama\PanoramaService;
use App\Services\Tours\TourPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * JSON endpoints used by the tour editor. Every mutation returns the full,
 * refreshed editor payload so the client never drifts from the server.
 */
class TourSceneController extends Controller
{
    public function __construct(protected TourPresenter $presenter) {}

    /**
     * Create one scene per selected panorama, appended in order.
     */
    public function store(Request $request, VirtualTour $tour, EntitlementService $entitlements, PanoramaService $panoramas): JsonResponse
    {
        Gate::authorize('update', $tour);

        $validated = $request->validate([
            'media_asset_ids' => ['required', 'array', 'min:1', 'max:50'],
            'media_asset_ids.*' => [
                'string',
                'distinct',
                Rule::exists(MediaAsset::class, 'ulid')
                    ->where('workspace_id', $tour->workspace_id)
                    ->where('asset_type', AssetType::Panorama->value),
            ],
        ]);

        $entitlements->ensureCanAdd($tour->workspace_id, Entitlement::ScenesPerTour, count($validated['media_asset_ids']), $tour);

        $assets = MediaAsset::whereIn('ulid', $validated['media_asset_ids'])->get()->keyBy('ulid');

        // Flat photos are turned into 360° scenes by AI when it is available; they join the tour when ready.
        $converting = $panoramas->convertFlatImages($assets->values(), $tour, $request->user());
        $convertingIds = $converting->pluck('ulid')->all();

        DB::transaction(function () use ($tour, $validated, $assets, $convertingIds): void {
            $order = (int) $tour->scenes()->max('sort_order') + count($convertingIds);

            foreach ($validated['media_asset_ids'] as $ulid) {
                if (in_array($ulid, $convertingIds, true)) {
                    continue;
                }

                $asset = $assets[$ulid];

                $tour->scenes()->create([
                    'media_asset_id' => $asset->id,
                    'title' => $this->titleFromFilename($asset->original_filename),
                    'sort_order' => ++$order,
                ]);
            }
        });

        $tour->touch();

        return response()->json([
            'tour' => $this->presenter->forEditor($tour->fresh()),
            'converting' => $converting->count(),
        ], 201);
    }

    public function update(SceneRequest $request, VirtualTour $tour, TourScene $scene): JsonResponse
    {
        Gate::authorize('update', $tour);

        $scene->update($request->sceneAttributes());
        $tour->touch();

        return $this->respond($tour);
    }

    /**
     * Copy a scene and its hotspots, placing the copy directly after it.
     */
    public function duplicate(VirtualTour $tour, TourScene $scene): JsonResponse
    {
        Gate::authorize('update', $tour);

        DB::transaction(function () use ($tour, $scene): void {
            $tour->scenes()->where('sort_order', '>', $scene->sort_order)->increment('sort_order');

            $copy = $scene->replicate(['ulid']);
            $copy->title = Str::limit("{$scene->title} (copy)", 120, '');
            $copy->sort_order = $scene->sort_order + 1;
            $copy->save();

            foreach ($scene->hotspots as $hotspot) {
                $hotspotCopy = $hotspot->replicate(['ulid']);
                $hotspotCopy->tour_scene_id = $copy->id;
                $hotspotCopy->save();
            }
        });

        $tour->touch();

        return $this->respond($tour, 201);
    }

    /**
     * Set the scene order from the full list of scene IDs.
     */
    public function reorder(Request $request, VirtualTour $tour): JsonResponse
    {
        Gate::authorize('update', $tour);

        $validated = $request->validate([
            'scene_ids' => ['required', 'array'],
            'scene_ids.*' => ['string', 'distinct'],
        ]);

        $scenes = $tour->scenes()->get(['id', 'ulid'])->keyBy('ulid');

        if (count($validated['scene_ids']) !== $scenes->count() || $scenes->keys()->diff($validated['scene_ids'])->isNotEmpty()) {
            throw ValidationException::withMessages(['scene_ids' => __('The scene list is out of date. Reload the editor and try again.')]);
        }

        DB::transaction(function () use ($validated, $scenes): void {
            foreach ($validated['scene_ids'] as $position => $ulid) {
                TourScene::whereKey($scenes[$ulid]->id)->update(['sort_order' => $position + 1]);
            }
        });

        $tour->touch();

        return $this->respond($tour);
    }

    /**
     * Delete a scene. Hotspots elsewhere that lead to it are removed too.
     */
    public function destroy(VirtualTour $tour, TourScene $scene): JsonResponse
    {
        Gate::authorize('update', $tour);

        $scene->delete();

        if (($tour->viewer_settings['start_scene_id'] ?? null) === $scene->ulid) {
            $tour->viewer_settings = array_merge($tour->viewer_settings ?? [], ['start_scene_id' => null]);
        }

        $tour->touch();
        $tour->save();

        return $this->respond($tour);
    }

    protected function respond(VirtualTour $tour, int $status = 200): JsonResponse
    {
        return response()->json(['tour' => $this->presenter->forEditor($tour->fresh())], $status);
    }

    protected function titleFromFilename(?string $filename): string
    {
        $name = Str::of((string) $filename)
            ->beforeLast('.')
            ->replaceMatches('/[_\-]+/', ' ')
            ->squish()
            ->title()
            ->limit(120, '')
            ->toString();

        return $name !== '' ? $name : 'Untitled scene';
    }
}
