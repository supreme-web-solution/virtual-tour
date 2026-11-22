<?php

namespace App\Http\Controllers\AI;

use App\Contracts\AI\StructuredOutputProvider;
use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\PropertyType;
use App\Http\Controllers\AI\Concerns\ResolvesGenerationTargets;
use App\Http\Controllers\Controller;
use App\Jobs\AI\GenerateScenePlan;
use App\Models\AiGeneration;
use App\Models\Property;
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
 * Text-to-360 tours: describe → AI scene plan → review/edit → generate.
 * No panorama is generated until the user confirms the plan.
 */
class ScenePlanController extends Controller
{
    use ResolvesGenerationTargets;

    public function store(Request $request, StructuredOutputProvider $ai, CreditService $credits): RedirectResponse
    {
        Gate::authorize('create', AiGeneration::class);

        /** @var User $user */
        $user = $request->user();

        if (! $ai->isConfigured()) {
            throw ValidationException::withMessages(['description' => __('AI planning is not configured. An administrator must add the OpenAI API key.')]);
        }

        $brief = $request->validate([
            'description' => ['required', 'string', 'min:20', 'max:'.config('ai.limits.max_description_length')],
            'property_id' => ['nullable', 'string', Rule::exists(Property::class, 'ulid')->where('workspace_id', $user->current_workspace_id)->whereNull('deleted_at')],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'scene_count' => ['required', 'integer', 'between:1,'.config('ai.limits.max_scenes_per_plan')],
            'interior_style' => ['nullable', 'string', 'max:100'],
            'architectural_style' => ['nullable', 'string', 'max:100'],
            'lighting' => ['nullable', 'string', 'max:100'],
            'time_of_day' => ['nullable', 'string', 'max:50'],
            'color_palette' => ['nullable', 'string', 'max:150'],
            'atmosphere' => ['nullable', 'string', 'max:150'],
            'additional_instructions' => ['nullable', 'string', 'max:1000'],
            'negative_prompt' => ['nullable', 'string', 'max:400'],
        ]);

        $generation = DB::transaction(function () use ($brief, $user, $credits): AiGeneration {
            $generation = new AiGeneration([
                'user_id' => $user->id,
                'property_id' => filled($brief['property_id'] ?? null) ? Property::where('ulid', $brief['property_id'])->value('id') : null,
                'generation_type' => GenerationType::ScenePlan,
                'provider' => 'openai',
                'model' => config('ai.providers.openai.text_model'),
                'status' => GenerationStatus::Queued,
                'input_payload' => $brief,
            ]);
            $generation->workspace_id = (int) $user->current_workspace_id;
            $generation->save();

            $credits->reserve($generation);

            return $generation;
        });

        app(GenerationLifecycle::class)->dispatchOrFail($generation, function () use ($generation): void {
            GenerateScenePlan::dispatch($generation);
        });

        return to_route('ai-studio.plans.show', $generation);
    }

    public function show(Request $request, AiGeneration $plan, GenerationPresenter $presenter): Response
    {
        Gate::authorize('view', $plan);
        abort_unless($plan->generation_type === GenerationType::ScenePlan, 404);

        /** @var User $user */
        $user = $request->user();

        $children = $plan->children()->with('tour')->latest()->get();

        return Inertia::render('ai-studio/Plan', [
            'plan' => [
                ...$presenter->summary($plan, $user),
                'brief' => $plan->input_payload,
                'result' => $plan->output_metadata['plan'] ?? null,
                'property_id' => $plan->property?->ulid,
            ],
            'generations' => $children->map(fn (AiGeneration $generation): array => $presenter->summary($generation, $user))->values(),
            'hasActive' => $plan->status->isActive() || $children->contains(fn (AiGeneration $child): bool => $child->status->isActive()),
            'tours' => VirtualTour::where('workspace_id', $plan->workspace_id)->latest('updated_at')->limit(100)->get(['ulid', 'title'])
                ->map(fn (VirtualTour $tour): array => ['id' => $tour->ulid, 'title' => $tour->title])->values(),
            'pricePerPanorama' => GenerationType::TextToPanorama->credits(),
            'available' => app(CreditService::class)->available($plan->workspace_id),
            'can' => ['generate' => $user->can('update', $plan)],
        ]);
    }

    /**
     * Generate panoramas for the reviewed scenes.
     */
    public function generate(Request $request, AiGeneration $plan, PanoramaGenerationProvider $provider, PanoramaService $panoramas): RedirectResponse
    {
        Gate::authorize('update', $plan);
        abort_unless($plan->generation_type === GenerationType::ScenePlan && $plan->status === GenerationStatus::Completed, 404);

        /** @var User $user */
        $user = $request->user();
        $planKeys = array_column($plan->output_metadata['plan']['scenes'] ?? [], 'key');

        $data = $request->validate([
            'style_id' => ['required', 'integer'],
            'scenes' => ['required', 'array', 'min:1', 'max:'.config('ai.limits.max_scenes_per_plan')],
            'scenes.*.key' => ['required', 'string', 'distinct', Rule::in($planKeys)],
            'scenes.*.title' => ['required', 'string', 'max:120'],
            'scenes.*.panorama_prompt' => ['required', 'string', 'min:10', 'max:2000'],
            'scenes.*.negative_prompt' => ['nullable', 'string', 'max:600'],
            ...$this->tourTargetRules($user, allowNone: false),
        ]);

        $style = $this->resolveStyle($provider, (int) $data['style_id'], imageToPanorama: false);
        $this->assertPromptLengths($data['scenes'], $style->maxPromptLength, $style->maxNegativeLength);

        // Connections come from the stored plan, not from the request.
        $connections = collect(array_filter((array) ($plan->output_metadata['plan']['scenes'] ?? []), 'is_array'))->pluck('connections', 'key');
        $scenes = array_map(fn (array $scene): array => [
            ...$scene,
            'negative_prompt' => $scene['negative_prompt'] ?? null,
            'connections' => array_values(array_intersect((array) ($connections[$scene['key']] ?? []), array_column($data['scenes'], 'key'))),
        ], array_values($data['scenes']));

        $tour = DB::transaction(function () use ($data, $user, $plan, $scenes, $style, $panoramas): VirtualTour {
            $tour = $this->resolveTour([
                ...$data,
                'tour_title' => $data['tour_title'] ?? ($plan->output_metadata['plan']['tour_title'] ?? null),
            ], $user, $plan->property_id, count($scenes));

            if ($tour->wasRecentlyCreated && blank($tour->description)) {
                $tour->update(['description' => $plan->output_metadata['plan']['tour_description'] ?? null]);
            }

            $panoramas->createFromPlan($plan, $scenes, $style->id, $tour, $user);

            return $tour;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generating :count panoramas. They appear in “:tour” as they finish.', ['count' => count($scenes), 'tour' => $tour->title])]);

        return to_route('ai-studio.plans.show', $plan);
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     */
    protected function assertPromptLengths(array $scenes, int $maxPrompt, int $maxNegative): void
    {
        $errors = [];

        foreach ($scenes as $index => $scene) {
            if (mb_strlen((string) $scene['panorama_prompt']) > $maxPrompt) {
                $errors["scenes.{$index}.panorama_prompt"] = __('This style allows at most :max characters.', ['max' => $maxPrompt]);
            }

            if (mb_strlen((string) ($scene['negative_prompt'] ?? '')) > $maxNegative) {
                $errors["scenes.{$index}.negative_prompt"] = __('This style allows at most :max characters.', ['max' => $maxNegative]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
