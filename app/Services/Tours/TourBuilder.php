<?php

namespace App\Services\Tours;

use App\Contracts\AI\StructuredOutputProvider;
use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Enums\AssetType;
use App\Enums\Entitlement;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\HotspotType;
use App\Enums\TourVisibility;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlanLimitReachedException;
use App\Exceptions\ProviderException;
use App\Jobs\AI\AnalysePropertyPhotos;
use App\Jobs\AI\GenerateScenePlan;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\PropertyImport;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\ActivityLogger;
use App\Services\AI\GenerationLifecycle;
use App\Services\Billing\EntitlementService;
use App\Services\Credits\CreditService;
use App\Services\Import\PropertyImportService;
use App\Services\Panorama\PanoramaService;
use App\Services\Panorama\PanoramaStyle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One-click tour creation — the four ways in:
 *
 * - describe: AI plans the scenes, then generates every 360° scene;
 * - photos:   AI reads the photos, then turns each into a 360° scene;
 * - panoramas: the user's own 360° photos become scenes straight away;
 * - link:     a listing's photos are imported, then handled like photos.
 *
 * Every way ends with scenes linked by navigation hotspots. The tour's
 * build_state records the step so the create dialog can show progress,
 * and failures are reported there instead of being lost in a queue.
 */
class TourBuilder
{
    public const PANORAMA_TYPES = [GenerationType::TextToPanorama, GenerationType::PhotoToPanorama];

    public function __construct(
        protected StructuredOutputProvider $ai,
        protected PanoramaGenerationProvider $panoramaProvider,
        protected PanoramaService $panoramas,
        protected CreditService $credits,
        protected EntitlementService $entitlements,
        protected ActivityLogger $activity,
        protected GenerationLifecycle $lifecycle,
    ) {}

    /**
     * Credit prices the dialog shows before anything starts.
     *
     * @return array{plan: int, text_scene: int, photo_scene: int, analysis: int}
     */
    public function prices(): array
    {
        return [
            'plan' => GenerationType::ScenePlan->credits(),
            'text_scene' => GenerationType::TextToPanorama->credits(),
            'photo_scene' => GenerationType::PhotoToPanorama->credits(),
            'analysis' => GenerationType::PhotoAnalysis->credits(),
        ];
    }

    /**
     * @param  array{description: string, scene_count: int, property_type?: string|null, property_id?: int|null}  $data
     */
    public function describe(User $user, array $data): VirtualTour
    {
        $this->requireAi();
        $this->requirePanoramas();
        $workspaceId = (int) $user->current_workspace_id;
        $count = (int) $data['scene_count'];

        $this->checkRoom($workspaceId, $count, $this->prices()['plan'] + $count * $this->prices()['text_scene']);

        [$tour, $plan] = DB::transaction(function () use ($user, $data, $count, $workspaceId): array {
            $tour = $this->newTour($user, Str::limit(Str::before($data['description'], "\n"), 60), $data['property_id'] ?? null, 'describe', 'planning', $count);

            $plan = new AiGeneration([
                'user_id' => $user->id,
                'property_id' => $data['property_id'] ?? null,
                'virtual_tour_id' => $tour->id,
                'generation_type' => GenerationType::ScenePlan,
                'provider' => 'openai',
                'model' => config('ai.providers.openai.text_model'),
                'status' => GenerationStatus::Queued,
                'input_payload' => [
                    'description' => $data['description'],
                    'scene_count' => $count,
                    'property_type' => $data['property_type'] ?? 'residential',
                    'auto_tour_id' => $tour->id,
                ],
            ]);
            $plan->workspace_id = $workspaceId;
            $plan->save();
            $this->credits->reserve($plan);

            $tour->forceFill(['build_state' => [...$tour->build_state, 'generation_id' => $plan->id]])->save();

            return [$tour, $plan];
        });

        $this->lifecycle->dispatchOrFail($plan, function () use ($plan): void {
            GenerateScenePlan::dispatch($plan)->afterCommit();
        });

        return $tour;
    }

    /**
     * Ordinary photos → 360° scenes (read by AI first when it is available).
     * Without AI 360° generation, or when the user opts out, the photos become
     * scenes as they are.
     *
     * @param  Collection<int, MediaAsset>  $photos
     */
    public function photos(User $user, Collection $photos, ?int $propertyId, ?string $title = null, bool $convert = true): VirtualTour
    {
        if (! $convert || ! $this->panoramaProvider->isConfigured()) {
            return $this->asScenes($user, $photos, $propertyId, $title);
        }

        $workspaceId = (int) $user->current_workspace_id;
        $analyse = $this->ai->isConfigured() && $photos->count() > 0;

        $this->checkRoom($workspaceId, $photos->count(), ($analyse ? $this->prices()['analysis'] : 0) + $photos->count() * $this->prices()['photo_scene']);

        $tour = DB::transaction(fn (): VirtualTour => $this->newTour($user, $title ?: __('New tour'), $propertyId, 'photos', 'analysing', $photos->count()));

        $this->startPhotoConversion($tour, $user, $photos);

        return $tour->refresh();
    }

    /**
     * Photos used directly as scenes, linked in order. Free: no AI involved.
     *
     * @param  Collection<int, MediaAsset>  $photos
     */
    protected function asScenes(User $user, Collection $photos, ?int $propertyId, ?string $title): VirtualTour
    {
        $this->checkRoom((int) $user->current_workspace_id, $photos->count(), 0);

        $tour = DB::transaction(function () use ($user, $photos, $propertyId, $title): VirtualTour {
            $tour = $this->newTour($user, $title ?: __('New tour'), $propertyId, 'photos', 'ready', $photos->count());
            $this->addPanoramaScenes($tour, $photos);

            return $tour;
        });

        $this->activity->log($tour->workspace_id, $user, 'tour.created', "Created tour {$tour->title} from {$photos->count()} photos", $tour);

        return $tour->refresh();
    }

    /**
     * The user's own 360° photos become scenes right away, linked in order.
     *
     * @param  Collection<int, MediaAsset>  $panoramas
     */
    public function panoramas(User $user, Collection $panoramas, ?int $propertyId, ?string $title = null): VirtualTour
    {
        $workspaceId = (int) $user->current_workspace_id;
        $this->checkRoom($workspaceId, $panoramas->count(), 0);

        $tour = DB::transaction(function () use ($user, $panoramas, $propertyId, $title): VirtualTour {
            $tour = $this->newTour($user, $title ?: __('New tour'), $propertyId, 'panoramas', 'ready', $panoramas->count());
            $this->addPanoramaScenes($tour, $panoramas);

            return $tour;
        });

        $this->activity->log($tour->workspace_id, $user, 'tour.created', "Created tour {$tour->title} from {$panoramas->count()} 360° photos", $tour);

        return $tour->refresh();
    }

    /**
     * A reviewed listing import: create the property, copy the chosen photos,
     * then turn them into 360° scenes once they arrive.
     *
     * @param  array<string, mixed>  $details
     * @param  list<string>  $imageUrls
     */
    public function fromImport(User $user, PropertyImport $import, array $details, array $imageUrls): VirtualTour
    {
        $this->requirePanoramas();
        $workspaceId = (int) $user->current_workspace_id;

        if ($imageUrls === []) {
            throw ValidationException::withMessages(['image_urls' => __('Choose at least one photo for the tour.')]);
        }

        $this->checkRoom($workspaceId, count($imageUrls), ($this->ai->isConfigured() ? $this->prices()['analysis'] : 0) + count($imageUrls) * $this->prices()['photo_scene']);

        $tour = $this->newTour($user, (string) ($details['name'] ?? $import->page_title ?? __('Imported tour')), null, 'link', 'importing', count($imageUrls));

        try {
            $import->virtual_tour_id = $tour->id;
            $import->save();
            $property = app(PropertyImportService::class)->complete($import, $user, $details, $imageUrls, null);
        } catch (Throwable $exception) {
            $tour->forceDelete();

            throw $exception;
        }

        $tour->property_id = $property->id;
        $tour->save();

        return $tour->refresh();
    }

    /**
     * Called once a listing import's photos are in the media library.
     */
    public function importFinished(PropertyImport $import): void
    {
        $tour = $import->tour;

        if ($tour === null || ($tour->build_state['stage'] ?? null) !== 'importing' || $import->user === null) {
            return;
        }

        $assets = MediaAsset::where('workspace_id', $import->workspace_id)
            ->where('metadata->import->import_id', $import->ulid)
            ->orderBy('id')
            ->get();

        if ($assets->isEmpty()) {
            $this->fail($tour, __('None of the listing photos could be copied. Upload photos instead.'));

            return;
        }

        $panoramas = $assets->where('asset_type', AssetType::Panorama)->values();
        $photos = $assets->where('asset_type', AssetType::Photo)->values();

        if ($panoramas->isNotEmpty()) {
            $this->addPanoramaScenes($tour, $panoramas);
        }

        if ($photos->isEmpty()) {
            $this->setState($tour, ['stage' => 'ready', 'total' => $panoramas->count()]);

            return;
        }

        $this->setState($tour, ['stage' => 'analysing', 'total' => $assets->count()]);
        $this->startPhotoConversion($tour, $import->user, $photos);
    }

    /**
     * A plan or analysis belonging to a build finished.
     */
    public function generationFinished(AiGeneration $generation): void
    {
        $tour = VirtualTour::whereKey((int) $generation->input('auto_tour_id'))->first();

        if ($tour === null || $generation->user === null) {
            return;
        }

        $stage = $tour->build_state['stage'] ?? null;

        if ($generation->generation_type === GenerationType::ScenePlan && $stage === 'planning') {
            $generation->status === GenerationStatus::Completed
                ? $this->generateFromPlan($tour, $generation)
                : $this->fail($tour, __('The AI could not plan this tour: :reason', ['reason' => $generation->error_message ?? __('unknown error')]));

            return;
        }

        if ($generation->generation_type === GenerationType::PhotoAnalysis && $stage === 'analysing') {
            $photos = MediaAsset::where('workspace_id', $tour->workspace_id)->whereIn('ulid', (array) $generation->input('photo_ids', []))->get();

            // If the analysis failed, convert the photos anyway with plain prompts.
            $this->convertPhotos($tour, $generation->user, $photos, $generation->status === GenerationStatus::Completed ? $generation : null);
        }
    }

    /**
     * Progress for the create dialog and the tours list.
     *
     * @return array<string, mixed>
     */
    public function status(VirtualTour $tour): array
    {
        $state = $tour->build_state ?? ['stage' => 'ready'];
        $stage = (string) ($state['stage'] ?? 'ready');
        $generations = $this->buildGenerations($tour, $state);

        if ($stage === 'generating' && $generations->isNotEmpty() && $generations->every(fn (AiGeneration $generation): bool => $generation->status->isFinal())) {
            $stage = $tour->scenes()->exists() ? 'ready' : 'failed';

            // Link the scenes now, in case the last one to finish was a failure.
            AiGeneration::whereIn('id', $generations->pluck('parent_id')->filter()->unique())->get()
                ->each(fn (AiGeneration $parent) => $this->panoramas->linkSiblingScenes($parent));

            $state = $this->setState($tour, [
                'stage' => $stage,
                'error' => $stage === 'failed' ? __('None of the scenes could be generated.') : null,
            ]);
        }

        $tour->loadMissing('scenes.mediaAsset');
        $scenes = $generations->isNotEmpty()
            ? $generations->map(function (AiGeneration $generation) use ($tour): array {
                $scene = $tour->scenes->firstWhere('ulid', $generation->output_metadata['scene_id'] ?? null);

                return [
                    'title' => (string) $generation->input('title', __('Scene')),
                    'status' => $generation->status->isActive() ? 'working' : ($generation->status === GenerationStatus::Completed ? 'done' : 'failed'),
                    'thumbnail_url' => $scene?->mediaAsset->thumbnail_url,
                ];
            })->values()->all()
            : $tour->scenes->sortBy('sort_order')->map(fn (TourScene $scene): array => [
                'title' => $scene->title,
                'status' => 'done',
                'thumbnail_url' => $scene->mediaAsset->thumbnail_url,
            ])->values()->all();

        $done = collect($scenes)->where('status', '!=', 'working')->count();

        return [
            'id' => $tour->ulid,
            'title' => $tour->title,
            'mode' => $state['mode'] ?? null,
            'stage' => $stage,
            'total' => max((int) ($state['total'] ?? 0), count($scenes)),
            'done' => $stage === 'ready' ? count($scenes) : $done,
            'scenes' => $scenes,
            'error' => $state['error'] ?? null,
            'is_finished' => in_array($stage, ['ready', 'failed'], true),
            'scene_count' => $tour->scenes()->count(),
        ];
    }

    protected function generateFromPlan(VirtualTour $tour, AiGeneration $plan): void
    {
        $result = (array) ($plan->output_metadata['plan'] ?? []);
        $scenes = array_values(array_filter((array) ($result['scenes'] ?? []), 'is_array'));

        try {
            $style = $this->style(imageToPanorama: false);
            $keys = array_column($scenes, 'key');

            $items = array_map(fn (array $scene): array => [
                'key' => (string) $scene['key'],
                'title' => (string) $scene['title'],
                'panorama_prompt' => Str::limit((string) $scene['panorama_prompt'], $style->maxPromptLength, ''),
                'negative_prompt' => filled($scene['negative_prompt'] ?? null) ? Str::limit((string) $scene['negative_prompt'], $style->maxNegativeLength, '') : null,
                'connections' => array_values(array_intersect((array) ($scene['connections'] ?? []), $keys)),
            ], $scenes);

            $tour->update(array_filter([
                'title' => $result['tour_title'] ?? null,
                'description' => $result['tour_description'] ?? null,
            ]));

            $this->panoramas->createFromPlan($plan, $items, $style->id, $tour, $plan->user);
            $this->setState($tour, ['stage' => 'generating', 'total' => count($items)]);
        } catch (InsufficientCreditsException|ProviderException|PlanLimitReachedException $exception) {
            $this->fail($tour, $exception->getMessage());
        }
    }

    /**
     * @param  Collection<int, MediaAsset>  $photos
     */
    protected function startPhotoConversion(VirtualTour $tour, User $user, Collection $photos): void
    {
        if (! $this->ai->isConfigured()) {
            $this->convertPhotos($tour, $user, $photos, null);

            return;
        }

        $analysis = DB::transaction(function () use ($tour, $user, $photos): AiGeneration {
            $analysis = new AiGeneration([
                'user_id' => $user->id,
                'property_id' => $tour->property_id,
                'virtual_tour_id' => $tour->id,
                'generation_type' => GenerationType::PhotoAnalysis,
                'provider' => 'openai',
                'model' => config('ai.providers.openai.vision_model'),
                'status' => GenerationStatus::Queued,
                'input_payload' => ['photo_ids' => $photos->pluck('ulid')->values()->all(), 'auto_tour_id' => $tour->id],
            ]);
            $analysis->workspace_id = $tour->workspace_id;
            $analysis->save();
            $this->credits->reserve($analysis);

            return $analysis;
        });

        $this->setState($tour, ['stage' => 'analysing', 'generation_id' => $analysis->id]);
        $this->lifecycle->dispatchOrFail($analysis, function () use ($analysis): void {
            AnalysePropertyPhotos::dispatch($analysis)->afterCommit();
        });
    }

    /**
     * @param  Collection<int, MediaAsset>  $photos
     */
    protected function convertPhotos(VirtualTour $tour, User $user, Collection $photos, ?AiGeneration $analysis): void
    {
        try {
            $style = $this->style(imageToPanorama: true);
            $byId = $photos->keyBy('ulid');
            $read = collect((array) ($analysis?->output_metadata['analysis']['images'] ?? []))->keyBy('asset_id');

            // Walkthrough order and duplicates come from the analysis when there is one.
            $order = $read->isNotEmpty()
                ? $read->reject(fn (array $image): bool => filled($image['duplicate_of'] ?? null))->keys()->filter(fn ($id): bool => $byId->has($id))->values()
                : $byId->keys()->values();

            $connections = [];

            foreach ((array) ($analysis?->output_metadata['analysis']['connections'] ?? []) as $pair) {
                $connections[$pair['from']][] = $pair['to'];
                $connections[$pair['to']][] = $pair['from'];
            }

            $ids = array_values(array_map('strval', $order->all()));
            $items = [];

            foreach ($ids as $index => $id) {
                $photo = $byId->get($id);

                if (! $photo instanceof MediaAsset) {
                    continue;
                }

                $image = (array) $read->get($id, []);
                $prompt = trim(implode(' ', array_filter([
                    $image['description'] ?? null,
                    ($image['features'] ?? []) !== [] ? 'Features: '.implode(', ', (array) $image['features']).'.' : null,
                ]))) ?: 'A realistic 360° view of this space, keeping its architecture, materials and lighting.';

                // Without AI connections, link each scene to the next and previous one.
                $links = $connections[$id] ?? array_values(array_filter([$ids[$index - 1] ?? null, $ids[$index + 1] ?? null]));

                $items[] = [
                    'photo' => $photo,
                    'prompt' => Str::limit($prompt, $style->maxPromptLength, ''),
                    'title' => filled($image['title'] ?? null) ? (string) $image['title'] : null,
                    'key' => $id,
                    'connections' => array_values(array_map('strval', array_unique(array_intersect($links, $ids)))),
                ];
            }

            if ($items === []) {
                $this->fail($tour, __('None of the photos could be used.'));

                return;
            }

            $parent = $analysis ?? $this->linkingParent($tour, $user);
            $this->panoramas->createFromPhotos($items, null, $style->id, $tour, $user, $parent);
            $this->setState($tour, ['stage' => 'generating', 'total' => count($items)]);
        } catch (InsufficientCreditsException|ProviderException|PlanLimitReachedException $exception) {
            $this->fail($tour, $exception->getMessage());
        }
    }

    /**
     * Without an analysis, panoramas still need a parent so the scenes are
     * linked once they all finish. A free, completed placeholder plays that role.
     */
    protected function linkingParent(VirtualTour $tour, User $user): AiGeneration
    {
        $parent = new AiGeneration([
            'user_id' => $user->id,
            'property_id' => $tour->property_id,
            'virtual_tour_id' => $tour->id,
            'generation_type' => GenerationType::PhotoAnalysis,
            'provider' => 'internal',
            'status' => GenerationStatus::Completed,
            'input_payload' => ['skipped' => true],
            'completed_at' => now(),
        ]);
        $parent->workspace_id = $tour->workspace_id;
        $parent->save();

        return $parent;
    }

    /**
     * @param  Collection<int, MediaAsset>  $panoramas
     */
    protected function addPanoramaScenes(VirtualTour $tour, Collection $panoramas): void
    {
        $order = (int) $tour->scenes()->max('sort_order');
        $scenes = [];

        foreach ($panoramas as $asset) {
            $scenes[] = $tour->scenes()->create([
                'media_asset_id' => $asset->id,
                'title' => Str::of((string) $asset->original_filename)->beforeLast('.')->replace(['_', '-'], ' ')->replaceMatches('/\b360\b/i', '')->squish()->title()->toString() ?: __('Scene :n', ['n' => $order + 1]),
                'sort_order' => ++$order,
            ]);
        }

        // Link each scene to the next and back, as a starting point to adjust in the editor.
        foreach ($scenes as $index => $scene) {
            $targets = array_values(array_filter([$scenes[$index + 1] ?? null, $scenes[$index - 1] ?? null]));

            foreach ($targets as $position => $target) {
                $scene->hotspots()->create([
                    'target_scene_id' => $target->id,
                    'type' => HotspotType::Navigation,
                    'yaw' => $position === 0 ? 0 : 180,
                    'pitch' => -12,
                    'settings' => ['suggested' => true],
                    'sort_order' => $position + 1,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return Collection<int, AiGeneration>
     */
    protected function buildGenerations(VirtualTour $tour, array $state): Collection
    {
        if (! isset($state['started_at'])) {
            return collect();
        }

        return AiGeneration::where('virtual_tour_id', $tour->id)
            ->whereIn('generation_type', self::PANORAMA_TYPES)
            ->where('created_at', '>=', $state['started_at'])
            ->orderBy('id')
            ->get();
    }

    protected function newTour(User $user, string $title, ?int $propertyId, string $mode, string $stage, int $total): VirtualTour
    {
        $tour = new VirtualTour(['title' => $title ?: __('New tour'), 'visibility' => TourVisibility::Unlisted]);
        $tour->workspace_id = (int) $user->current_workspace_id;
        $tour->property_id = $propertyId;
        $tour->created_by = $user->id;
        $tour->build_state = ['mode' => $mode, 'stage' => $stage, 'total' => $total, 'error' => null, 'started_at' => now()->subSecond()->toDateTimeString()];
        $tour->save();

        return $tour;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    protected function setState(VirtualTour $tour, array $changes): array
    {
        $state = [...($tour->refresh()->build_state ?? []), ...$changes];
        $tour->forceFill(['build_state' => $state])->save();

        return $state;
    }

    protected function fail(VirtualTour $tour, string $message): void
    {
        $this->setState($tour, ['stage' => 'failed', 'error' => $message]);
    }

    protected function style(bool $imageToPanorama): PanoramaStyle
    {
        $styles = $this->panoramaProvider->styles($imageToPanorama);
        $preferred = (int) config('ai.providers.skybox.default_style_id');

        foreach ($styles as $style) {
            if ($style->id === $preferred) {
                return $style;
            }
        }

        return $styles[0] ?? throw new ProviderException(__('No 360° style is available right now.'));
    }

    /**
     * Plan limits and credits are checked before anything starts.
     */
    protected function checkRoom(int $workspaceId, int $scenes, int $credits): void
    {
        $this->entitlements->ensureCanAdd($workspaceId, Entitlement::Tours);
        $this->entitlements->ensureCanAdd($workspaceId, Entitlement::ScenesPerTour, $scenes);

        $available = $this->credits->available($workspaceId);

        if ($credits > $available) {
            throw ValidationException::withMessages(['credits' => __('This needs about :need credits and you have :have.', ['need' => $credits, 'have' => $available])]);
        }
    }

    protected function requireAi(): void
    {
        if (! $this->ai->isConfigured()) {
            throw ValidationException::withMessages(['mode' => __('AI planning is not set up yet. An administrator must add the OpenAI API key.')]);
        }
    }

    protected function requirePanoramas(): void
    {
        if (! $this->panoramaProvider->isConfigured()) {
            throw ValidationException::withMessages(['mode' => __('AI 360° generation is not set up yet. An administrator must add the Skybox API key. You can still build a tour from your own 360° photos.')]);
        }
    }
}
