<?php

namespace App\Http\Controllers;

use App\Contracts\Image\ImageEnhancementProvider;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\Image\FalUpscaleProvider;
use App\Services\Image\PhotoEnhancementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * AI photo studio, used from the media library: start an edit and follow it.
 * The original photo is never changed; the edit becomes a new library photo.
 */
class PhotoEnhanceController extends Controller
{
    public function __construct(protected PhotoEnhancementService $enhancements) {}

    /**
     * What the dialog shows before anything starts: the ways to edit, prices
     * and the workspace's credits.
     */
    public function options(Request $request, ImageEnhancementProvider $provider, FalUpscaleProvider $upscaler, CreditService $credits): JsonResponse
    {
        $user = $this->user($request);
        Gate::authorize('create', MediaAsset::class);

        return response()->json([
            'configured' => $provider->isConfigured(),
            'upscale_configured' => $upscaler->isConfigured(),
            'modes' => PhotoEnhancementService::modes(),
            'credits' => [
                'medium' => $this->enhancements->credits('medium'),
                'high' => $this->enhancements->credits('high'),
                'upscale' => $this->enhancements->credits('medium', 'upscale'),
            ],
            'available' => $credits->available((int) $user->current_workspace_id),
        ]);
    }

    public function store(Request $request, MediaAsset $media, ImageEnhancementProvider $provider, CreditService $credits): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($media->workspace_id === $user->current_workspace_id, 404);
        Gate::authorize('create', MediaAsset::class);

        $data = $request->validate([
            'mode' => ['required', Rule::in(PhotoEnhancementService::MODES)],
            'quality' => ['required', Rule::in(['medium', 'high'])],
        ]);

        $generation = $this->enhancements->start($user, $media, $data['mode'], $data['quality']);

        return response()->json([
            'enhancement' => $this->payload($generation->refresh()),
            'available' => $credits->available((int) $user->current_workspace_id),
        ], 201);
    }

    public function show(Request $request, AiGeneration $generation): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(
            $generation->workspace_id === $user->current_workspace_id && $generation->generation_type === GenerationType::PhotoEnhance,
            404,
        );

        return response()->json(['enhancement' => $this->payload($generation)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(AiGeneration $generation): array
    {
        $result = $generation->status === GenerationStatus::Completed
            ? MediaAsset::where('workspace_id', $generation->workspace_id)->where('ulid', $generation->output_metadata['media_asset_id'] ?? '')->first()
            : null;
        $original = MediaAsset::where('workspace_id', $generation->workspace_id)->where('ulid', $generation->input('source_asset_id'))->first();

        return [
            'id' => $generation->ulid,
            'status' => match (true) {
                $generation->status === GenerationStatus::Completed => 'done',
                $generation->status->isFinal() => 'failed',
                default => 'working',
            },
            'mode' => $generation->input('mode'),
            'credits' => (int) $generation->input('credits', 0),
            'error' => $generation->status->isFinal() && $generation->status !== GenerationStatus::Completed ? $generation->error_message : null,
            'original' => $original === null ? null : ['id' => $original->ulid, 'name' => $original->original_filename, 'url' => $original->secure_url, 'width' => $original->width, 'height' => $original->height],
            'result' => $result === null ? null : ['id' => $result->ulid, 'name' => $result->original_filename, 'url' => $result->secure_url, 'width' => $result->width, 'height' => $result->height],
        ];
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
