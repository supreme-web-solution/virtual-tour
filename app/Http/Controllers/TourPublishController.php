<?php

namespace App\Http\Controllers;

use App\Enums\TourStatus;
use App\Models\VirtualTour;
use App\Services\ActivityLogger;
use App\Services\Tours\TourPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TourPublishController extends Controller
{
    public function __construct(
        protected TourPresenter $presenter,
        protected ActivityLogger $activity,
    ) {}

    /**
     * Make the tour available at its share link.
     */
    public function store(Request $request, VirtualTour $tour): JsonResponse
    {
        Gate::authorize('publish', $tour);

        if (! $tour->scenes()->where('is_visible', true)->exists()) {
            throw ValidationException::withMessages(['tour' => __('Add at least one visible scene before publishing.')]);
        }

        $tour->status = TourStatus::Published;
        $tour->published_at = now();
        $tour->save();

        $this->activity->log($tour->workspace_id, $request->user(), 'tour.published', "Published tour {$tour->title}", $tour);

        return response()->json(['tour' => $this->presenter->forEditor($tour->fresh())]);
    }

    /**
     * Take the tour offline; its share link stops working.
     */
    public function destroy(Request $request, VirtualTour $tour): JsonResponse
    {
        Gate::authorize('publish', $tour);

        $tour->status = TourStatus::Draft;
        $tour->save();

        $this->activity->log($tour->workspace_id, $request->user(), 'tour.unpublished', "Unpublished tour {$tour->title}", $tour);

        return response()->json(['tour' => $this->presenter->forEditor($tour->fresh())]);
    }
}
