<?php

namespace App\Http\Controllers\AI;

use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Enums\AssetType;
use App\Http\Controllers\AI\Concerns\ResolvesGenerationTargets;
use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Panorama\PanoramaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Photo-to-360: AI outpainting around an ordinary photo. The result is a
 * synthetic panorama, not a physically accurate capture.
 */
class PhotoPanoramaController extends Controller
{
    use ResolvesGenerationTargets;

    public function store(Request $request, PanoramaGenerationProvider $provider, PanoramaService $panoramas): RedirectResponse
    {
        Gate::authorize('create', AiGeneration::class);

        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'photo_id' => ['required', 'string', Rule::exists(MediaAsset::class, 'ulid')
                ->where('workspace_id', $user->current_workspace_id)
                ->where('asset_type', AssetType::Photo->value)],
            'prompt' => ['required', 'string', 'min:5', 'max:2000'],
            'negative_prompt' => ['nullable', 'string', 'max:600'],
            'style_id' => ['required', 'integer'],
            ...$this->tourTargetRules($user),
        ]);

        $style = $this->resolveStyle($provider, (int) $data['style_id'], imageToPanorama: true);

        if (mb_strlen($data['prompt']) > $style->maxPromptLength) {
            throw ValidationException::withMessages(['prompt' => __('This style allows at most :max characters.', ['max' => $style->maxPromptLength])]);
        }

        $photo = MediaAsset::where('ulid', $data['photo_id'])->firstOrFail();

        DB::transaction(function () use ($data, $user, $photo, $style, $panoramas): void {
            $tour = $this->resolveTour($data, $user, $photo->property_id);
            $panoramas->createFromPhoto($photo, $data['prompt'], $data['negative_prompt'] ?? null, $style->id, $tour, $user);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Converting the photo into a 360° panorama. This usually takes a couple of minutes.')]);

        return to_route('ai-studio.index');
    }
}
