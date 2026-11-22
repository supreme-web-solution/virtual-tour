<?php

namespace App\Http\Controllers\AI;

use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Exceptions\ProviderException;
use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\User;
use App\Services\AI\GenerationLifecycle;
use App\Services\Panorama\PanoramaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class GenerationController extends Controller
{
    /**
     * Run one panorama again without touching the rest of the plan.
     */
    public function regenerate(Request $request, AiGeneration $generation, PanoramaService $panoramas): RedirectResponse
    {
        Gate::authorize('update', $generation);
        abort_unless($generation->generation_type->producesPanorama() && $generation->status->isFinal(), 422);

        $data = $request->validate([
            'prompt' => ['nullable', 'string', 'min:5', 'max:2000'],
            'negative_prompt' => ['nullable', 'string', 'max:600'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $panoramas->regenerate($generation, $user, $data['prompt'] ?? null, $data['negative_prompt'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Regenerating “:title”.', ['title' => $generation->input('title', 'panorama')])]);

        return back();
    }

    /**
     * Stop a generation that hasn't finished; its credits are released.
     */
    public function cancel(AiGeneration $generation, PanoramaGenerationProvider $provider, GenerationLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('update', $generation);
        abort_unless($generation->generation_type->producesPanorama(), 422);

        if ($generation->status->isActive() && $generation->provider_request_id) {
            try {
                $provider->cancel($generation->provider_request_id);
            } catch (ProviderException $exception) {
                // It may already be finishing; record and release our side anyway.
                Log::warning('Provider cancel failed', ['generation' => $generation->ulid, 'error' => $exception->getMessage()]);
            }
        }

        $lifecycle->cancel($generation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation cancelled. Its credits were released.')]);

        return back();
    }
}
