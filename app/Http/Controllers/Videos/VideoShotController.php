<?php

namespace App\Http\Controllers\Videos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Videos\ShotRequest;
use App\Models\VideoProject;
use App\Models\VideoShot;
use App\Services\Video\VideoStudioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VideoShotController extends Controller
{
    public function __construct(protected VideoStudioService $studio) {}

    /**
     * Edit a shot. Changing what the clip shows marks its clip out of date;
     * the old clip stays in the media library.
     */
    public function update(ShotRequest $request, VideoProject $video, VideoShot $shot): RedirectResponse
    {
        Gate::authorize('update', $video);
        $this->assertNotGenerating($shot);

        $shot->fill($request->shotAttributes());

        if ($shot->isDirty(ShotRequest::CONTENT_FIELDS) && $shot->status !== 'pending') {
            $shot->status = 'pending';
            $shot->output_asset_id = null;
        }

        $shot->save();
        $this->studio->refreshStatus($video);

        return back();
    }

    public function reorder(Request $request, VideoProject $video): RedirectResponse
    {
        Gate::authorize('update', $video);

        $data = $request->validate(['shot_ids' => ['required', 'array'], 'shot_ids.*' => ['string', 'distinct']]);
        $shots = $video->shots()->get(['id', 'ulid'])->keyBy('ulid');

        if (count($data['shot_ids']) !== $shots->count() || $shots->keys()->diff($data['shot_ids'])->isNotEmpty()) {
            throw ValidationException::withMessages(['shot_ids' => __('The storyboard is out of date. Reload and try again.')]);
        }

        DB::transaction(function () use ($data, $shots): void {
            foreach ($data['shot_ids'] as $position => $ulid) {
                VideoShot::whereKey($shots[$ulid]->id)->update(['sort_order' => $position + 1]);
            }
        });

        return back();
    }

    public function destroy(VideoProject $video, VideoShot $shot): RedirectResponse
    {
        Gate::authorize('update', $video);
        $this->assertNotGenerating($shot);

        if ($video->shots()->count() <= 1) {
            throw ValidationException::withMessages(['shot' => __('A video needs at least one shot.')]);
        }

        $shot->delete();
        $this->studio->refreshStatus($video);

        return back();
    }

    protected function assertNotGenerating(VideoShot $shot): void
    {
        if ($shot->status === 'generating') {
            throw ValidationException::withMessages(['shot' => __('Wait for this clip to finish generating before changing it.')]);
        }
    }
}
