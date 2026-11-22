<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\ProviderWebhookEvent;
use App\Services\Panorama\PanoramaService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Skybox status updates. The signature is verified against the raw
 * body; each (request, status) is processed once. A webhook is only a hint:
 * the panorama is retrieved and validated before anything is marked complete.
 */
class SkyboxWebhookController extends Controller
{
    public function __invoke(Request $request, PanoramaGenerationProvider $provider, PanoramaService $panoramas): JsonResponse
    {
        $headers = [
            'x-signature' => $request->header('x-signature'),
            'x-timestamp' => $request->header('x-timestamp'),
        ];

        $status = $provider->parseWebhook($request->getContent(), $headers);

        if ($status === null) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        try {
            $event = ProviderWebhookEvent::create([
                'provider' => $provider->name(),
                'event_key' => mb_substr($status->requestId.':'.$status->state.':'.($status->raw['updated_at'] ?? ''), 0, 191),
                'payload' => $status->raw,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Already processed.']);
        }

        $generation = AiGeneration::where('provider', $provider->name())
            ->where('provider_request_id', $status->requestId)
            ->first();

        if ($generation) {
            $panoramas->applyStatus($generation, $status);
        }

        $event->update(['processed_at' => now()]);

        return response()->json(['message' => 'OK']);
    }
}
