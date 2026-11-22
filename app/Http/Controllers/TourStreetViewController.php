<?php

namespace App\Http\Controllers;

use App\Enums\Entitlement;
use App\Exceptions\ProviderException;
use App\Models\GoogleConnection;
use App\Models\StreetViewPublication;
use App\Models\TourScene;
use App\Models\VirtualTour;
use App\Services\Billing\EntitlementService;
use App\Services\Google\GoogleOAuth;
use App\Services\StreetView\StreetViewPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Publish a tour's own 360° photos to Google Street View, from the editor.
 */
class TourStreetViewController extends Controller
{
    public function __construct(
        protected StreetViewPublisher $publisher,
        protected GoogleOAuth $oauth,
        protected EntitlementService $entitlements,
    ) {}

    public function show(Request $request, VirtualTour $tour): JsonResponse
    {
        Gate::authorize('view', $tour);

        return response()->json($this->payload($request, $tour));
    }

    public function store(Request $request, VirtualTour $tour): JsonResponse
    {
        Gate::authorize('update', $tour);
        $this->ensureReady($tour);

        $data = $request->validate([
            'scene_ids' => ['required', 'array', 'min:1', 'max:50'],
            'scene_ids.*' => ['string', 'size:26'],
        ]);

        $scenes = $tour->scenes()->with('mediaAsset')->whereIn('ulid', $data['scene_ids'])->get();

        foreach ($scenes as $scene) {
            if ($this->publisher->problem($scene) === null) {
                $this->publisher->queue($scene, $tour->workspace_id);
            }
        }

        return response()->json($this->payload($request, $tour), 202);
    }

    public function destroy(Request $request, VirtualTour $tour, string $scene): JsonResponse
    {
        Gate::authorize('update', $tour);

        $publication = StreetViewPublication::query()
            ->whereHas('scene', fn ($query) => $query->where('virtual_tour_id', $tour->id)->where('ulid', $scene))
            ->firstOrFail();

        try {
            $this->publisher->unpublish($publication);
        } catch (ProviderException $exception) {
            throw ValidationException::withMessages(['scene' => $exception->getMessage()]);
        }

        return response()->json($this->payload($request, $tour));
    }

    protected function ensureReady(VirtualTour $tour): void
    {
        if (! $this->entitlements->allows($tour->workspace_id, Entitlement::StreetView)) {
            throw ValidationException::withMessages(['scene_ids' => __('Street View publishing is not part of this workspace plan.')]);
        }

        if (! GoogleConnection::where('workspace_id', $tour->workspace_id)->exists()) {
            throw ValidationException::withMessages(['scene_ids' => __('Connect your Google account first.')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request, VirtualTour $tour): array
    {
        $connection = GoogleConnection::where('workspace_id', $tour->workspace_id)->first();
        $publications = StreetViewPublication::whereIn('tour_scene_id', $tour->scenes()->pluck('id'))->get()->keyBy('tour_scene_id');
        $user = $request->user();

        return [
            'allowed' => $this->entitlements->allows($tour->workspace_id, Entitlement::StreetView),
            'configured' => $this->oauth->isConfigured(),
            'can_connect' => $user?->roleIn($tour->workspace_id)?->canManage() ?? false,
            'connection' => $connection === null ? null : ['email' => $connection->google_email],
            'redirect_uri' => $this->oauth->redirectUri(),
            'scenes' => $tour->scenes()->with('mediaAsset')->get()->map(function (TourScene $scene) use ($publications): array {
                $publication = $publications->get($scene->id);

                return [
                    'id' => $scene->ulid,
                    'title' => $scene->title,
                    'thumbnail_url' => $scene->mediaAsset->thumbnail_url,
                    'problem' => $this->publisher->problem($scene),
                    'publication' => $publication === null ? null : [
                        'status' => $publication->status,
                        'share_link' => $publication->share_link,
                        'error' => $publication->error,
                    ],
                ];
            })->values()->all(),
        ];
    }
}
