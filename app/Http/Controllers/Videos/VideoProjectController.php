<?php

namespace App\Http\Controllers\Videos;

use App\Contracts\Video\VideoGenerationProvider;
use App\Enums\AssetType;
use App\Enums\Entitlement;
use App\Enums\GenerationType;
use App\Enums\SoundtrackMode;
use App\Enums\TourVisibility;
use App\Enums\VideoStyle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Videos\VideoRequest;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoShot;
use App\Models\VirtualTour;
use App\Services\ActivityLogger;
use App\Services\Billing\EntitlementService;
use App\Services\CommentPresenter;
use App\Services\Credits\CreditService;
use App\Services\Video\VideoPresenter;
use App\Services\Video\VideoStudioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class VideoProjectController extends Controller
{
    public function __construct(
        protected VideoStudioService $studio,
        protected VideoPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', VideoProject::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('videos/Index', [
            'videos' => VideoProject::where('workspace_id', $user->current_workspace_id)
                ->with(['property', 'posterAsset'])
                ->latest('updated_at')
                ->paginate(12)
                ->through(fn (VideoProject $project): array => $this->presenter->summary($project, $user)),
            'can' => ['create' => $user->can('create', VideoProject::class)],
        ]);
    }

    /**
     * The creation wizard.
     */
    public function create(Request $request, VideoGenerationProvider $provider, CreditService $credits): Response
    {
        Gate::authorize('create', VideoProject::class);

        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->current_workspace_id;
        $fromTour = $request->filled('tour')
            ? VirtualTour::where('workspace_id', $workspaceId)->where('ulid', $request->string('tour')->toString())->with('property:id,ulid')->first()
            : null;

        return Inertia::render('videos/Create', [
            'sources' => $this->sourceLibrary($workspaceId),
            'audio' => $this->audioLibrary($workspaceId),
            'properties' => Property::where('workspace_id', $workspaceId)->orderBy('name')->get(['ulid', 'name'])
                ->map(fn (Property $property): array => ['id' => $property->ulid, 'name' => $property->name])->values(),
            'tours' => VirtualTour::where('workspace_id', $workspaceId)->latest('updated_at')->limit(100)->get(['ulid', 'title', 'property_id'])
                ->map(fn (VirtualTour $tour): array => ['id' => $tour->ulid, 'title' => $tour->title])->values(),
            'styles' => VideoStyle::options(),
            'aspectRatios' => VideoProject::ASPECT_RATIOS,
            'durations' => VideoProject::DURATIONS,
            'moods' => SoundtrackMode::MOODS,
            'ambiences' => SoundtrackMode::AMBIENCES,
            'maxSources' => (int) config('ai.video.max_sources', 9),
            'limits' => $provider->capabilities(),
            'prices' => [
                'storyboard' => GenerationType::VideoStoryboard->credits(),
                'clip_per_second' => (float) config('ai.operations.video_clip.credits_per_second'),
                'music' => GenerationType::MusicTrack->credits(),
            ],
            'available' => $credits->available($workspaceId),
            'defaultPropertyId' => $fromTour?->property->ulid ?? ($request->string('property')->toString() ?: null),
            'fromTour' => $fromTour === null ? null : [
                'id' => $fromTour->ulid,
                'title' => $fromTour->title,
                // Scenes in tour order, up to the wizard's limit.
                'source_ids' => array_values($fromTour->scenes()->orderBy('sort_order')->with('mediaAsset:id,ulid')->limit((int) config('ai.video.max_sources', 9))->get()
                    ->map(fn (TourScene $scene): string => $scene->mediaAsset->ulid)->all()),
            ],
        ]);
    }

    public function store(VideoRequest $request, EntitlementService $entitlements): RedirectResponse
    {
        Gate::authorize('create', VideoProject::class);

        /** @var User $user */
        $user = $request->user();
        $entitlements->ensureAllowed((int) $user->current_workspace_id, Entitlement::VideoStudio);
        $entitlements->ensureCanAdd((int) $user->current_workspace_id, Entitlement::VideosPerMonth);

        $project = $this->studio->create($request->projectAttributes(), $request->sourceIds(), $user);

        return to_route('videos.show', $project);
    }

    /**
     * The studio: storyboard, clips, sound, render and sharing.
     */
    public function show(Request $request, VideoProject $video, VideoGenerationProvider $provider, CreditService $credits): Response
    {
        Gate::authorize('view', $video);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('videos/Show', [
            'comments' => fn (): array => app(CommentPresenter::class)->for($video, $user),
            'video' => $this->presenter->project($video, $user),
            'sources' => $this->sourceLibrary($video->workspace_id),
            'audio' => $this->audioLibrary($video->workspace_id),
            'cameraMovements' => VideoShot::CAMERA_MOVEMENTS,
            'transitions' => VideoShot::TRANSITIONS,
            'moods' => SoundtrackMode::MOODS,
            'ambiences' => SoundtrackMode::AMBIENCES,
            'visibilityOptions' => TourVisibility::options(),
            'limits' => $provider->capabilities(),
            'clipCost' => $this->presenter->clipCost($video, $this->studio->needsMusic($video)),
            'musicCost' => GenerationType::MusicTrack->credits(),
            'available' => $credits->available($video->workspace_id),
            'features' => ['video' => $provider->isConfigured()],
            'can' => [
                'update' => $user->can('update', $video),
                'delete' => $user->can('delete', $video),
            ],
        ]);
    }

    public function update(VideoRequest $request, VideoProject $video): RedirectResponse
    {
        Gate::authorize('update', $video);

        $data = $request->projectAttributes();
        $video->fill(collect($data)->only(['title', 'property_id', 'virtual_tour_id', 'voiceover_asset_id'])->all());

        if (isset($data['soundtrack_mode'])) {
            $changed = $data['soundtrack_mode'] !== $video->soundtrack_mode->value;
            $video->soundtrack_mode = SoundtrackMode::from($data['soundtrack_mode']);

            // A new mode starts without the previous mode's track.
            if ($changed) {
                $video->soundtrack_asset_id = $data['soundtrack_asset_id'] ?? null;
            }
        }

        if (array_key_exists('soundtrack_asset_id', $data) && $video->soundtrack_mode === SoundtrackMode::OwnAudio) {
            $video->soundtrack_asset_id = $data['soundtrack_asset_id'];
        }

        if (isset($data['settings'])) {
            $video->settings = array_merge($video->settings ?? [], $data['settings']);
        }

        $video->save();
        $this->studio->refreshStatus($video);

        return back();
    }

    public function destroy(Request $request, VideoProject $video, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('delete', $video);

        $video->delete();
        $activity->log($video->workspace_id, $request->user(), 'video.deleted', "Deleted video {$video->title}", $video);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Video deleted. Its clips stay in the media library.')]);

        return to_route('videos.index');
    }

    /**
     * Photos and 360° panoramas that can be animated.
     *
     * @return list<array<string, mixed>>
     */
    protected function sourceLibrary(int $workspaceId): array
    {
        return array_values(MediaAsset::where('workspace_id', $workspaceId)
            ->whereIn('asset_type', [AssetType::Photo, AssetType::Panorama])
            ->with('property:id,ulid,name')
            ->latest()
            ->limit(300)
            ->get()
            ->map(fn (MediaAsset $asset): array => [
                'id' => $asset->ulid,
                'filename' => $asset->original_filename,
                'thumbnail_url' => $asset->thumbnail_url,
                'is_panorama' => $asset->asset_type === AssetType::Panorama,
                'property_id' => $asset->property?->ulid,
                'property' => $asset->property?->name,
            ])
            ->all());
    }

    /**
     * @return list<array{id: string, name: string|null, url: string}>
     */
    protected function audioLibrary(int $workspaceId): array
    {
        return array_values(MediaAsset::where('workspace_id', $workspaceId)
            ->where('asset_type', AssetType::Audio)
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (MediaAsset $asset): array => ['id' => $asset->ulid, 'name' => $asset->original_filename, 'url' => $asset->secure_url])
            ->all());
    }
}
