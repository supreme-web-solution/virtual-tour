<?php

namespace App\Http\Controllers\AI;

use App\Contracts\AI\StructuredOutputProvider;
use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Enums\AssetType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Http\Controllers\AI\Concerns\ResolvesGenerationTargets;
use App\Http\Controllers\Controller;
use App\Jobs\AI\AnalysePropertyPhotos;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\AI\GenerationLifecycle;
use App\Services\AI\GenerationPresenter;
use App\Services\Credits\CreditService;
use App\Services\Panorama\PanoramaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Photos → AI analysis (rooms, quality, order) → user corrections → convert
 * the selected photos into panoramas and assemble a tour.
 */
class PhotoAnalysisController extends Controller
{
    use ResolvesGenerationTargets;

    public function store(Request $request, StructuredOutputProvider $ai, CreditService $credits): RedirectResponse
    {
        Gate::authorize('create', AiGeneration::class);

        /** @var User $user */
        $user = $request->user();

        if (! $ai->isConfigured()) {
            throw ValidationException::withMessages(['photo_ids' => __('AI analysis is not configured. An administrator must add the OpenAI API key.')]);
        }

        $data = $request->validate([
            'photo_ids' => ['required', 'array', 'min:1', 'max:'.config('ai.providers.openai.max_photos_per_analysis')],
            'photo_ids.*' => ['string', 'distinct', Rule::exists(MediaAsset::class, 'ulid')
                ->where('workspace_id', $user->current_workspace_id)
                ->where('asset_type', AssetType::Photo->value)],
        ]);

        $propertyId = MediaAsset::whereIn('ulid', $data['photo_ids'])->whereNotNull('property_id')->value('property_id');

        $generation = DB::transaction(function () use ($data, $user, $credits, $propertyId): AiGeneration {
            $generation = new AiGeneration([
                'user_id' => $user->id,
                'property_id' => $propertyId,
                'generation_type' => GenerationType::PhotoAnalysis,
                'provider' => 'openai',
                'model' => config('ai.providers.openai.vision_model'),
                'status' => GenerationStatus::Queued,
                'input_payload' => ['photo_ids' => array_values($data['photo_ids'])],
            ]);
            $generation->workspace_id = (int) $user->current_workspace_id;
            $generation->save();

            $credits->reserve($generation);

            return $generation;
        });

        app(GenerationLifecycle::class)->dispatchOrFail($generation, function () use ($generation): void {
            AnalysePropertyPhotos::dispatch($generation);
        });

        return to_route('ai-studio.analyses.show', $generation);
    }

    public function show(Request $request, AiGeneration $analysis, GenerationPresenter $presenter): Response
    {
        Gate::authorize('view', $analysis);
        abort_unless($analysis->generation_type === GenerationType::PhotoAnalysis, 404);

        /** @var User $user */
        $user = $request->user();
        $children = $analysis->children()->with('tour')->latest()->get();

        $photos = MediaAsset::where('workspace_id', $analysis->workspace_id)
            ->whereIn('ulid', (array) $analysis->input('photo_ids', []))
            ->get(['ulid', 'thumbnail_url', 'original_filename', 'width', 'height'])
            ->keyBy('ulid')
            ->map(fn (MediaAsset $photo): array => [
                'id' => $photo->ulid,
                'thumbnail_url' => $photo->thumbnail_url,
                'filename' => $photo->original_filename,
            ]);

        return Inertia::render('ai-studio/Analysis', [
            'analysis' => [
                ...$presenter->summary($analysis, $user),
                'result' => $analysis->output_metadata['analysis'] ?? null,
                'property_id' => $analysis->property?->ulid,
                'property_name' => $analysis->property?->name,
            ],
            'photos' => $photos,
            'generations' => $children->map(fn (AiGeneration $generation): array => $presenter->summary($generation, $user))->values(),
            'hasActive' => $analysis->status->isActive() || $children->contains(fn (AiGeneration $child): bool => $child->status->isActive()),
            'tours' => VirtualTour::where('workspace_id', $analysis->workspace_id)->latest('updated_at')->limit(100)->get(['ulid', 'title'])
                ->map(fn (VirtualTour $tour): array => ['id' => $tour->ulid, 'title' => $tour->title])->values(),
            'pricePerPanorama' => GenerationType::PhotoToPanorama->credits(),
            'available' => app(CreditService::class)->available($analysis->workspace_id),
            'can' => ['convert' => $user->can('update', $analysis)],
        ]);
    }

    /**
     * Convert the photos the user selected (with their corrected titles).
     */
    public function convert(Request $request, AiGeneration $analysis, PanoramaGenerationProvider $provider, PanoramaService $panoramas): RedirectResponse
    {
        Gate::authorize('update', $analysis);
        abort_unless($analysis->generation_type === GenerationType::PhotoAnalysis && $analysis->status === GenerationStatus::Completed, 404);

        /** @var User $user */
        $user = $request->user();
        $analysed = (array) $analysis->input('photo_ids', []);

        $data = $request->validate([
            'style_id' => ['required', 'integer'],
            'negative_prompt' => ['nullable', 'string', 'max:600'],
            'items' => ['required', 'array', 'min:1', 'max:'.config('ai.providers.openai.max_photos_per_analysis')],
            'items.*.asset_id' => ['required', 'string', 'distinct', Rule::in($analysed)],
            'items.*.title' => ['required', 'string', 'max:120'],
            'items.*.prompt' => ['required', 'string', 'min:5', 'max:2000'],
            ...$this->tourTargetRules($user),
        ]);

        $style = $this->resolveStyle($provider, (int) $data['style_id'], imageToPanorama: true);

        $photos = MediaAsset::where('workspace_id', $analysis->workspace_id)->whereIn('ulid', array_column($data['items'], 'asset_id'))->get()->keyBy('ulid');
        $selected = array_column($data['items'], 'asset_id');

        // Connections between the selected photos, taken from the stored analysis.
        $connections = [];

        foreach ((array) ($analysis->output_metadata['analysis']['connections'] ?? []) as $pair) {
            if (in_array($pair['from'], $selected, true) && in_array($pair['to'], $selected, true)) {
                $connections[$pair['from']][] = $pair['to'];
                $connections[$pair['to']][] = $pair['from'];
            }
        }

        $items = array_map(fn (array $item): array => [
            'photo' => $photos[$item['asset_id']],
            'prompt' => $item['prompt'],
            'title' => $item['title'],
            'key' => $item['asset_id'],
            'connections' => array_values(array_unique($connections[$item['asset_id']] ?? [])),
        ], array_values($data['items']));

        DB::transaction(function () use ($data, $user, $analysis, $items, $style, $panoramas): void {
            $tour = $this->resolveTour($data, $user, $analysis->property_id, count($items));
            $panoramas->createFromPhotos($items, $data['negative_prompt'] ?? null, $style->id, $tour, $user, $analysis);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Converting :count photos into 360° panoramas.', ['count' => count($items)])]);

        return to_route('ai-studio.analyses.show', $analysis);
    }
}
