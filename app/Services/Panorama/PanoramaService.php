<?php

namespace App\Services\Panorama;

use App\Contracts\Media\MediaStorage;
use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\HotspotType;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\ProviderException;
use App\Jobs\Panorama\PollPanoramaGeneration;
use App\Jobs\Panorama\StoreGeneratedPanorama;
use App\Jobs\Panorama\SubmitPanoramaGeneration;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\AI\GenerationLifecycle;
use App\Services\Credits\CreditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates panorama generations: creating them (with credits held),
 * submitting to the provider, following their status, storing the result
 * permanently and placing it in a tour.
 */
class PanoramaService
{
    public function __construct(
        protected PanoramaGenerationProvider $provider,
        protected MediaStorage $storage,
        protected CreditService $credits,
        protected GenerationLifecycle $lifecycle,
        protected PanoramaValidationService $validator,
    ) {}

    /**
     * Create one text-to-panorama generation per reviewed scene. Credits for
     * all of them are held together, or nothing is created.
     *
     * Each scene has: key, title, panorama_prompt, negative_prompt (nullable) and connections (scene keys).
     *
     * @param  list<array<string, mixed>>  $scenes  Reviewed, validated scenes.
     * @return list<AiGeneration>
     */
    public function createFromPlan(AiGeneration $plan, array $scenes, int $styleId, VirtualTour $tour, User $user): array
    {
        $generations = DB::transaction(function () use ($plan, $scenes, $styleId, $tour, $user): array {
            $base = (int) $tour->scenes()->max('sort_order');
            $created = [];

            foreach ($scenes as $index => $scene) {
                $created[] = $this->newGeneration(GenerationType::TextToPanorama, $user, $tour, $plan, [
                    'key' => (string) $scene['key'],
                    'title' => (string) $scene['title'],
                    'prompt' => (string) $scene['panorama_prompt'],
                    'negative_prompt' => isset($scene['negative_prompt']) ? (string) $scene['negative_prompt'] : null,
                    'style_id' => $styleId,
                    'sort_order' => $base + $index + 1,
                    'connections' => array_values(array_map('strval', (array) ($scene['connections'] ?? []))),
                ], $plan->property_id);
            }

            $this->credits->reserveFor($created);

            return $created;
        });

        $this->dispatchSubmissions($generations);

        return $generations;
    }

    /**
     * Queue 360° conversion for the images that are not already
     * equirectangular. Returns the ones queued; when AI 360° is unavailable or
     * the credits are short, nothing is queued and the caller uses them as-is.
     *
     * @param  Collection<int, MediaAsset>  $assets
     * @return Collection<int, MediaAsset>
     */
    public function convertFlatImages(Collection $assets, VirtualTour $tour, User $user): Collection
    {
        $flat = $assets->reject(fn (MediaAsset $asset): bool => (bool) ($asset->metadata['panorama']['is_equirectangular'] ?? false))->values();

        if ($flat->isEmpty() || ! $this->provider->isConfigured()) {
            return new Collection;
        }

        try {
            $styles = $this->provider->styles(true);
            $preferred = (int) config('ai.providers.skybox.default_style_id');
            $style = collect($styles)->first(fn (PanoramaStyle $style): bool => $style->id === $preferred) ?? ($styles[0] ?? null);

            if ($style === null) {
                return new Collection;
            }

            $prompt = Str::limit('A realistic 360° view of this space, keeping its architecture, materials and lighting.', $style->maxPromptLength, '');

            $this->createFromPhotos(
                array_values($flat->map(fn (MediaAsset $asset): array => ['photo' => $asset, 'prompt' => $prompt])->all()),
                null,
                $style->id,
                $tour,
                $user,
            );
        } catch (InsufficientCreditsException|ProviderException) {
            return new Collection;
        }

        return $flat;
    }

    /**
     * Convert an ordinary photo into a panorama.
     *
     * @param  array{key?: string, title?: string, connections?: list<string>}  $extra
     */
    public function createFromPhoto(
        MediaAsset $photo,
        string $prompt,
        ?string $negativePrompt,
        int $styleId,
        ?VirtualTour $tour,
        User $user,
        ?AiGeneration $parent = null,
        array $extra = [],
        ?int $sortOrder = null,
    ): AiGeneration {
        return $this->createFromPhotos([[
            'photo' => $photo,
            'prompt' => $prompt,
            'title' => $extra['title'] ?? null,
            'key' => $extra['key'] ?? null,
            'connections' => $extra['connections'] ?? [],
        ]], $negativePrompt, $styleId, $tour, $user, $parent, $sortOrder)[0];
    }

    /**
     * Convert several photos at once; credits for all are held together.
     *
     * @param  list<array{photo: MediaAsset, prompt: string, title?: string|null, key?: string|null, connections?: list<string>}>  $items
     * @return list<AiGeneration>
     */
    public function createFromPhotos(
        array $items,
        ?string $negativePrompt,
        int $styleId,
        ?VirtualTour $tour,
        User $user,
        ?AiGeneration $parent = null,
        ?int $firstSortOrder = null,
    ): array {
        $generations = DB::transaction(function () use ($items, $negativePrompt, $styleId, $tour, $user, $parent, $firstSortOrder): array {
            $next = $firstSortOrder ?? ($tour ? (int) $tour->scenes()->max('sort_order') + 1 : null);
            $created = [];

            foreach ($items as $index => $item) {
                $photo = $item['photo'];

                $created[] = $this->newGeneration(GenerationType::PhotoToPanorama, $user, $tour, $parent, [
                    'key' => $item['key'] ?? $photo->ulid,
                    'title' => $item['title'] ?? Str::of((string) $photo->original_filename)->beforeLast('.')->replace(['_', '-'], ' ')->squish()->title()->toString(),
                    'prompt' => $item['prompt'],
                    'negative_prompt' => $negativePrompt,
                    'style_id' => $styleId,
                    'source_asset_id' => $photo->ulid,
                    'sort_order' => $next !== null ? $next + $index : null,
                    'connections' => $item['connections'] ?? [],
                ], $photo->property_id ?? $tour?->property_id);
            }

            $this->credits->reserveFor($created);

            return $created;
        });

        $this->dispatchSubmissions($generations);

        return $generations;
    }

    /**
     * Run the same scene again (optionally with an edited prompt). When the
     * original produced a tour scene, that scene's panorama is replaced.
     */
    public function regenerate(AiGeneration $original, User $user, ?string $prompt = null, ?string $negativePrompt = null): AiGeneration
    {
        $input = $original->input_payload ?? [];
        $input['prompt'] = $prompt ?? $input['prompt'];
        $input['negative_prompt'] = $negativePrompt ?? ($input['negative_prompt'] ?? null);
        $input['replace_scene_id'] = $original->output_metadata['scene_id'] ?? ($input['replace_scene_id'] ?? null);
        $input['regenerated_from'] = $original->ulid;
        $input['connections'] = [];

        $generation = DB::transaction(function () use ($original, $user, $input): AiGeneration {
            $generation = $this->newGeneration($original->generation_type, $user, $original->tour, $original->parent, $input, $original->property_id);
            $this->credits->reserve($generation);

            return $generation;
        });

        $this->dispatchSubmissions([$generation]);

        return $generation;
    }

    /**
     * Send a generation to the provider. Safe to call more than once.
     *
     * @throws ProviderException when the failure is transient (the job retries)
     */
    public function submit(AiGeneration $generation): void
    {
        $generation->refresh();

        if ($generation->status->isFinal() || $generation->provider_request_id !== null) {
            return;
        }

        $webhookUrl = filled(config('services.skybox.webhook_secret')) ? route('webhooks.skybox') : null;

        try {
            $status = $generation->generation_type === GenerationType::PhotoToPanorama
                ? $this->provider->convertImageToPanorama(
                    $this->sourceImageUrl($generation),
                    (string) $generation->input('prompt'),
                    $generation->input('negative_prompt'),
                    (int) $generation->input('style_id'),
                    $webhookUrl,
                )
                : $this->provider->generate(
                    (string) $generation->input('prompt'),
                    $generation->input('negative_prompt'),
                    (int) $generation->input('style_id'),
                    $webhookUrl,
                );
        } catch (ProviderException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            $this->lifecycle->fail($generation, $exception->getMessage());

            return;
        }

        $generation->forceFill([
            'provider_request_id' => $status->requestId,
            'model' => $status->model,
            'status' => GenerationStatus::Submitted,
            'started_at' => now(),
        ]);
        $generation->mergeOutput(['provider_credits' => $status->providerCredits]);
        $generation->estimated_cost = $this->providerCostUsd($status->providerCredits);
        $generation->save();

        PollPanoramaGeneration::dispatch($generation)
            ->delay(now()->addSeconds($this->pollDelay(0)));
    }

    /**
     * Apply a status reported by polling or a webhook.
     */
    public function applyStatus(AiGeneration $generation, PanoramaStatus $status): void
    {
        $generation->refresh();

        if ($generation->status->isFinal()) {
            return;
        }

        match ($status->state) {
            PanoramaStatus::COMPLETED => $this->completedRemotely($generation, $status),
            PanoramaStatus::FAILED => $this->lifecycle->fail($generation, $status->errorMessage ?: 'The panorama provider reported an error.'),
            PanoramaStatus::CANCELLED => $this->lifecycle->fail($generation, 'The generation was cancelled by the provider.'),
            PanoramaStatus::PROCESSING => $this->lifecycle->advance($generation, GenerationStatus::Processing),
            default => null,
        };
    }

    /**
     * Copy the finished panorama into permanent storage, record it, and add it
     * to the tour. Idempotent: a retry after success does nothing new.
     */
    public function store(AiGeneration $generation): void
    {
        $generation->refresh();

        if ($generation->status->isFinal()) {
            return;
        }

        if ($generation->outputs()->whereNotNull('media_asset_id')->exists()) {
            $this->lifecycle->complete($generation);

            return;
        }

        $fileUrl = $generation->output_metadata['file_url'] ?? null;

        if (! is_string($fileUrl) || ! Str::startsWith($fileUrl, 'https://')) {
            $this->lifecycle->fail($generation, 'The provider did not return a usable file.');

            return;
        }

        $this->lifecycle->advance($generation, GenerationStatus::Uploading);

        $workspace = $generation->workspace;
        $stored = $this->storage->uploadFromUrl($fileUrl, $workspace->storageFolder().'/generated');

        if ($stored->resourceType !== 'image' || ! $stored->width || ! $stored->height) {
            $this->storage->delete($stored->publicId, $stored->resourceType);
            $this->lifecycle->fail($generation, 'The generated file is not a valid image.');

            return;
        }

        DB::transaction(function () use ($generation, $stored): void {
            $asset = new MediaAsset([
                'property_id' => $generation->property_id,
                'uploaded_by' => $generation->user_id,
                'asset_type' => AssetType::Panorama,
                'source_type' => AssetSource::Generated,
                'original_filename' => Str::slug((string) $generation->input('title', 'panorama')).'-ai.'.($stored->format ?: 'jpg'),
                'mime_type' => $stored->mimeType(),
                'format' => $stored->format,
                'file_size' => $stored->bytes,
                'width' => $stored->width,
                'height' => $stored->height,
                'cloudinary_public_id' => $stored->publicId,
                'cloudinary_resource_type' => $stored->resourceType,
                'secure_url' => $stored->secureUrl,
                'thumbnail_url' => $this->storage->thumbnailUrl($stored),
                'provider' => $generation->provider,
                'generation_id' => $generation->id,
                'metadata' => [
                    'panorama' => $this->validator->inspect($stored->width, $stored->height),
                    'ai' => [
                        'synthetic' => true,
                        'type' => $generation->generation_type->value,
                        'prompt' => $generation->input('prompt'),
                        'style_id' => $generation->input('style_id'),
                    ],
                ],
            ]);
            $asset->workspace_id = $generation->workspace_id;
            $asset->save();

            $generation->outputs()->create(['media_asset_id' => $asset->id, 'output_type' => 'panorama']);

            if ($scene = $this->placeInTour($generation, $asset)) {
                $generation->mergeOutput(['scene_id' => $scene->ulid]);
            }

            $generation->mergeOutput(['media_asset_id' => $asset->ulid]);
            $generation->actual_cost = $generation->estimated_cost;
            $generation->save();
        });

        $this->lifecycle->complete($generation);

        if ($generation->parent) {
            $this->linkSiblingScenes($generation->parent);
        }
    }

    /**
     * Once every panorama from a plan or analysis has finished, add suggested
     * navigation hotspots between connected scenes. Their positions are
     * placeholders on the horizon for the user to adjust in the editor.
     */
    public function linkSiblingScenes(AiGeneration $parent): void
    {
        DB::transaction(function () use ($parent): void {
            $parent = AiGeneration::whereKey($parent->id)->lockForUpdate()->firstOrFail();

            if (($parent->output_metadata['scenes_linked'] ?? false) === true) {
                return;
            }

            $children = $parent->children()
                ->whereIn('generation_type', [GenerationType::TextToPanorama, GenerationType::PhotoToPanorama])
                ->get();

            if ($children->isEmpty() || $children->contains(fn (AiGeneration $child): bool => $child->status->isActive())) {
                return;
            }

            $sceneByKey = [];

            foreach ($children as $child) {
                $sceneId = $child->output_metadata['scene_id'] ?? null;
                $scene = $sceneId ? TourScene::where('ulid', $sceneId)->first() : null;

                if ($scene) {
                    $sceneByKey[(string) $child->input('key')] = $scene;
                }
            }

            foreach ($children as $child) {
                $from = $sceneByKey[(string) $child->input('key')] ?? null;
                $targets = array_values(array_filter(array_map(
                    fn ($key) => $sceneByKey[(string) $key] ?? null,
                    (array) $child->input('connections', []),
                )));

                if (! $from) {
                    continue;
                }

                foreach ($targets as $index => $target) {
                    if ($target->id === $from->id || $from->hotspots()->where('target_scene_id', $target->id)->exists()) {
                        continue;
                    }

                    $from->hotspots()->create([
                        'target_scene_id' => $target->id,
                        'type' => HotspotType::Navigation,
                        // Spread suggestions around the horizon until the user positions them.
                        'yaw' => round(-180 + ($index + 1) * 360 / (count($targets) + 1), 3),
                        'pitch' => -12,
                        'settings' => ['suggested' => true],
                        'sort_order' => $index + 1,
                    ]);
                }
            }

            $parent->mergeOutput(['scenes_linked' => true]);
            $parent->save();
        });
    }

    public function pollDelay(int $attempt): int
    {
        $delays = (array) config('ai.providers.skybox.poll_delays_seconds', [60]);

        return (int) ($delays[$attempt] ?? end($delays) ?: 300);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function newGeneration(GenerationType $type, User $user, ?VirtualTour $tour, ?AiGeneration $parent, array $input, ?int $propertyId): AiGeneration
    {
        $generation = new AiGeneration([
            'user_id' => $user->id,
            'property_id' => $propertyId,
            'virtual_tour_id' => $tour?->id,
            'parent_id' => $parent?->id,
            'generation_type' => $type,
            'provider' => $this->provider->name(),
            'status' => GenerationStatus::Queued,
            'input_payload' => $input,
        ]);
        $generation->workspace_id = $tour->workspace_id ?? $user->current_workspace_id;
        $generation->save();

        return $generation;
    }

    /**
     * @param  list<AiGeneration>  $generations
     */
    protected function dispatchSubmissions(array $generations): void
    {
        $this->lifecycle->dispatchOrFail($generations, function () use ($generations): void {
            foreach ($generations as $generation) {
                // Callers often create generations inside a transaction; never
                // submit work for rows that end up rolled back.
                SubmitPanoramaGeneration::dispatch($generation)->afterCommit();
            }
        });
    }

    protected function completedRemotely(AiGeneration $generation, PanoramaStatus $status): void
    {
        if (! $status->fileUrl) {
            $this->lifecycle->fail($generation, 'The provider finished without returning an image.');

            return;
        }

        $generation->mergeOutput([
            'file_url' => $status->fileUrl,
            'provider_credits' => $status->providerCredits ?? ($generation->output_metadata['provider_credits'] ?? null),
        ]);
        $generation->save();

        if ($this->lifecycle->advance($generation, GenerationStatus::Downloading)) {
            StoreGeneratedPanorama::dispatch($generation);
        }
    }

    protected function placeInTour(AiGeneration $generation, MediaAsset $asset): ?TourScene
    {
        $tour = $generation->tour;

        if (! $tour) {
            return null;
        }

        $replaceId = $generation->input('replace_scene_id');

        if ($replaceId && $scene = $tour->scenes()->where('ulid', $replaceId)->first()) {
            $scene->update(['media_asset_id' => $asset->id]);

            return $scene;
        }

        return $tour->scenes()->create([
            'media_asset_id' => $asset->id,
            'title' => Str::limit((string) $generation->input('title', 'AI scene'), 120, ''),
            'sort_order' => (int) ($generation->input('sort_order') ?? ((int) $tour->scenes()->max('sort_order') + 1)),
            'metadata' => ['generation_id' => $generation->ulid],
        ]);
    }

    protected function sourceImageUrl(AiGeneration $generation): string
    {
        $photo = MediaAsset::where('ulid', $generation->input('source_asset_id'))
            ->where('workspace_id', $generation->workspace_id)
            ->first();

        if (! $photo) {
            throw new ProviderException('The source photo no longer exists.');
        }

        // A bounded-size copy keeps the request fast; the original is untouched.
        return $this->storage->scaledImageUrl($photo->cloudinary_public_id, 2048);
    }

    protected function providerCostUsd(?int $providerCredits): ?float
    {
        $rate = config('ai.providers.skybox.usd_per_credit');

        return $providerCredits !== null && is_numeric($rate) ? round($providerCredits * (float) $rate, 4) : null;
    }
}
