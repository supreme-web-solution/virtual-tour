<?php

namespace App\Http\Controllers\Videos;

use App\Enums\TourVisibility;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoShot;
use App\Services\ActivityLogger;
use App\Services\Video\VideoStudioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Actions that spend credits or change what the public sees.
 */
class VideoActionController extends Controller
{
    public function __construct(
        protected VideoStudioService $studio,
        protected ActivityLogger $activity,
    ) {}

    /**
     * Generate every clip that doesn't have one yet (and AI music if needed).
     */
    public function generateClips(Request $request, VideoProject $video): RedirectResponse
    {
        Gate::authorize('update', $video);

        $count = count($this->studio->generateClips($video, $this->user($request)));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generating :count item(s). Clips take a few minutes each.', ['count' => $count])]);

        return back();
    }

    /**
     * Regenerate one shot's clip without touching the others.
     */
    public function regenerateShot(Request $request, VideoProject $video, VideoShot $shot): RedirectResponse
    {
        Gate::authorize('update', $video);

        $this->studio->generateClips($video, $this->user($request), [$shot->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Regenerating this clip.')]);

        return back();
    }

    public function generateMusic(Request $request, VideoProject $video): RedirectResponse
    {
        Gate::authorize('update', $video);

        $data = $request->validate(['prompt' => ['nullable', 'string', 'max:600']]);
        $this->studio->generateMusic($video, $this->user($request), $data['prompt'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Composing a new soundtrack.')]);

        return back();
    }

    public function render(Request $request, VideoProject $video): RedirectResponse
    {
        Gate::authorize('update', $video);

        $this->studio->render($video, $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rendering your video. This usually takes under a minute.')]);

        return back();
    }

    public function publish(Request $request, VideoProject $video): RedirectResponse
    {
        Gate::authorize('update', $video);

        $data = $request->validate(['visibility' => ['required', Rule::enum(TourVisibility::class)]]);

        if ($video->final_asset_id === null) {
            throw ValidationException::withMessages(['publish' => __('Render the video before publishing it.')]);
        }

        $video->update(['visibility' => $data['visibility'], 'published_at' => $video->published_at ?? now()]);
        $this->activity->log($video->workspace_id, $request->user(), 'video.published', "Published video {$video->title}", $video);

        return back();
    }

    public function unpublish(Request $request, VideoProject $video): RedirectResponse
    {
        Gate::authorize('update', $video);

        $video->update(['published_at' => null]);

        return back();
    }

    protected function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
