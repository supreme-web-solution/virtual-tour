<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tours\HotspotRequest;
use App\Models\TourHotspot;
use App\Models\TourScene;
use App\Models\VirtualTour;
use App\Services\Tours\TourPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TourHotspotController extends Controller
{
    public function __construct(protected TourPresenter $presenter) {}

    public function store(HotspotRequest $request, VirtualTour $tour, TourScene $scene): JsonResponse
    {
        Gate::authorize('update', $tour);

        $hotspot = $scene->hotspots()->create([
            ...$request->hotspotAttributes(),
            'sort_order' => (int) $scene->hotspots()->max('sort_order') + 1,
        ]);

        $tour->touch();

        return response()->json([
            'tour' => $this->presenter->forEditor($tour->fresh()),
            'hotspot_id' => $hotspot->ulid,
        ], 201);
    }

    public function update(HotspotRequest $request, VirtualTour $tour, TourHotspot $hotspot): JsonResponse
    {
        Gate::authorize('update', $tour);

        $hotspot->update($request->hotspotAttributes());
        $tour->touch();

        return response()->json(['tour' => $this->presenter->forEditor($tour->fresh())]);
    }

    public function destroy(VirtualTour $tour, TourHotspot $hotspot): JsonResponse
    {
        Gate::authorize('update', $tour);

        $hotspot->delete();
        $tour->touch();

        return response()->json(['tour' => $this->presenter->forEditor($tour->fresh())]);
    }
}
