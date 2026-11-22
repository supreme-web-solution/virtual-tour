<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Models\MediaAsset;
use App\Models\TourFloorplan;
use App\Models\TourScene;
use App\Models\VirtualTour;
use App\Services\Tours\TourPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Floor plans for a tour, with one marker per scene placed on the image.
 */
class TourFloorplanController extends Controller
{
    public function __construct(protected TourPresenter $presenter) {}

    public function store(Request $request, VirtualTour $tour): JsonResponse
    {
        Gate::authorize('update', $tour);

        $data = $request->validate([
            'media_asset_id' => ['required', 'string', Rule::exists(MediaAsset::class, 'ulid')
                ->where('workspace_id', $tour->workspace_id)
                ->where('asset_type', AssetType::FloorPlan->value)],
            'title' => ['required', 'string', 'max:120'],
        ]);

        $tour->floorplans()->create([
            'media_asset_id' => MediaAsset::where('ulid', $data['media_asset_id'])->value('id'),
            'title' => $data['title'],
            'sort_order' => (int) $tour->floorplans()->max('sort_order') + 1,
        ]);

        return $this->respond($tour, 201);
    }

    public function update(Request $request, VirtualTour $tour, TourFloorplan $floorplan): JsonResponse
    {
        Gate::authorize('update', $tour);

        $floorplan->update($request->validate(['title' => ['required', 'string', 'max:120']]));

        return $this->respond($tour);
    }

    public function destroy(VirtualTour $tour, TourFloorplan $floorplan): JsonResponse
    {
        Gate::authorize('update', $tour);

        $floorplan->delete();

        return $this->respond($tour);
    }

    /**
     * Place (or move) a scene's marker on the floor plan. Coordinates are
     * fractions of the image width and height.
     */
    public function placeMarker(Request $request, VirtualTour $tour, TourFloorplan $floorplan): JsonResponse
    {
        Gate::authorize('update', $tour);

        $data = $request->validate([
            'scene_id' => ['required', 'string', Rule::exists(TourScene::class, 'ulid')->where('virtual_tour_id', $tour->id)],
            'x' => ['required', 'numeric', 'between:0,1'],
            'y' => ['required', 'numeric', 'between:0,1'],
        ]);

        $floorplan->markers()->updateOrCreate(
            ['tour_scene_id' => TourScene::where('ulid', $data['scene_id'])->value('id')],
            ['x' => $data['x'], 'y' => $data['y']],
        );

        return $this->respond($tour);
    }

    public function removeMarker(Request $request, VirtualTour $tour, TourFloorplan $floorplan): JsonResponse
    {
        Gate::authorize('update', $tour);

        $data = $request->validate([
            'scene_id' => ['required', 'string', Rule::exists(TourScene::class, 'ulid')->where('virtual_tour_id', $tour->id)],
        ]);

        $floorplan->markers()->where('tour_scene_id', TourScene::where('ulid', $data['scene_id'])->value('id'))->delete();

        return $this->respond($tour);
    }

    protected function respond(VirtualTour $tour, int $status = 200): JsonResponse
    {
        $tour->touch();

        return response()->json(['tour' => $this->presenter->forEditor($tour->fresh())], $status);
    }
}
