<?php

namespace App\Services\Import;

use App\Contracts\AI\StructuredOutputProvider;
use App\Contracts\Media\MediaStorage;
use App\Enums\Entitlement;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\PropertyStatus;
use App\Exceptions\ImportException;
use App\Exceptions\ProviderException;
use App\Jobs\Import\FetchListing;
use App\Jobs\Import\ImportListingImages;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\PropertyImport;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AI\GenerationLifecycle;
use App\Services\Billing\EntitlementService;
use App\Services\Credits\CreditService;
use App\Services\Media\MediaLibraryService;
use App\Services\Tours\TourBuilder;
use App\Support\TemporaryFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Property URL import: fetch a public listing page, show what was found,
 * then create the property and copy the photos the user selected.
 *
 * The user confirms they may use the images before anything is copied, and
 * sites that refuse automated access (robots.txt, 403, bot challenges) are
 * respected: the import fails and the user uploads manually.
 */
class PropertyImportService
{
    public const PAGE_TYPES = ['text/html', 'application/xhtml+xml'];

    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        protected SafeHttpClient $client,
        protected RobotsPolicy $robots,
        protected ListingParser $parser,
        protected ListingExtractionService $extractor,
        protected StructuredOutputProvider $ai,
        protected CreditService $credits,
        protected GenerationLifecycle $lifecycle,
        protected EntitlementService $entitlements,
        protected MediaStorage $storage,
        protected MediaLibraryService $library,
        protected ActivityLogger $activity,
    ) {}

    /**
     * Validate the address and queue the page fetch. AI extraction (one
     * credit) is used when OpenAI is configured.
     */
    public function start(User $user, string $url): PropertyImport
    {
        try {
            $target = $this->client->inspect($url);
        } catch (ImportException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }

        $import = DB::transaction(function () use ($user, $target): PropertyImport {
            $import = new PropertyImport([
                'url' => $target['url'],
                'source_host' => $target['host'],
                'status' => 'queued',
            ]);
            $import->workspace_id = (int) $user->current_workspace_id;
            $import->user_id = $user->id;

            if ($this->ai->isConfigured()) {
                $generation = new AiGeneration([
                    'user_id' => $user->id,
                    'generation_type' => GenerationType::ListingExtraction,
                    'provider' => $this->ai->name(),
                    'status' => GenerationStatus::Queued,
                    'input_payload' => ['url' => $target['url']],
                ]);
                $generation->workspace_id = $import->workspace_id;
                $generation->save();
                $this->credits->reserve($generation);
                $import->ai_generation_id = $generation->id;
            }

            $import->save();

            return $import;
        });

        $pending = $import->ai_generation_id ? AiGeneration::find($import->ai_generation_id) : null;

        if ($pending instanceof AiGeneration) {
            $this->lifecycle->dispatchOrFail($pending, function () use ($import): void {
                FetchListing::dispatch($import)->afterCommit();
            });
        } else {
            FetchListing::dispatch($import)->afterCommit();
        }

        return $import;
    }

    /**
     * Fetch and read the listing page (queued job).
     */
    public function fetch(PropertyImport $import): void
    {
        $import->refresh();

        if ($import->status !== 'queued') {
            return;
        }

        $import->update(['status' => 'fetching', 'error' => null]);
        $generation = $import->generation;

        try {
            if (! $this->robots->allows($import->url)) {
                throw new ImportException(__('This website asks automated tools not to read this page (robots.txt). Please upload the photos yourself instead.'));
            }

            $page = $this->client->fetch($import->url, self::PAGE_TYPES, (int) config('import.max_page_bytes'), 'text/html,application/xhtml+xml');

            try {
                $parsed = $this->parser->parse($page->body(), $page->url);
            } finally {
                TemporaryFiles::delete($page->path);
            }

            if ($parsed['is_challenge']) {
                throw new ImportException(__('This website showed a bot check instead of the listing. We do not bypass these checks, so please upload the photos yourself.'));
            }

            $details = $this->extractDetails($parsed, $page->url, $import, $generation);

            $import->update([
                'status' => 'ready',
                'page_title' => Str::limit((string) $parsed['title'], 250, '') ?: null,
                'details' => $details,
                'images' => $parsed['images'],
            ]);
        } catch (ImportException $exception) {
            $this->failImport($import, $exception->getMessage());
        }
    }

    /**
     * AI extraction when available; the page's own metadata otherwise. An AI
     * failure never fails the import: its credit is returned instead.
     *
     * @param  array{title: string|null, description: string|null, structured: array<string, mixed>, images: list<array{url: string, alt: string|null}>, text: string, is_challenge: bool}  $parsed
     * @return array<string, mixed>
     */
    protected function extractDetails(array $parsed, string $url, PropertyImport $import, ?AiGeneration $generation): array
    {
        if ($generation === null) {
            return $this->extractor->merge($parsed);
        }

        if ($parsed['text'] === '') {
            $this->lifecycle->fail($generation, 'The page had no readable text.');

            return $this->extractor->merge($parsed);
        }

        try {
            $this->lifecycle->advance($generation, GenerationStatus::Processing);
            ['details' => $details, 'result' => $result] = $this->extractor->extract($parsed, $url);
        } catch (ProviderException $exception) {
            $this->lifecycle->fail($generation, $exception->getMessage());

            return $this->extractor->merge($parsed);
        }

        $generation->refresh();
        $generation->model = $result->model;
        $generation->actual_cost = $result->costUsd;
        $generation->mergeOutput(['import_id' => $import->ulid]);
        $generation->save();
        $this->lifecycle->complete($generation);

        return $details;
    }

    public function failImport(PropertyImport $import, string $message): void
    {
        $import->update(['status' => 'failed', 'error' => $message]);

        $generation = $import->generation;

        if ($generation !== null && ! $generation->status->isFinal()) {
            $this->lifecycle->fail($generation, $message);
        }
    }

    /**
     * Create (or reuse) the property and queue copying the selected photos.
     *
     * @param  array<string, mixed>  $details  Validated property fields.
     * @param  list<string>  $imageUrls  Chosen from the import's candidates.
     */
    public function complete(PropertyImport $import, User $user, array $details, array $imageUrls, ?Property $existing): Property
    {
        if ($import->status !== 'ready') {
            throw ValidationException::withMessages(['import' => __('This import has already been used.')]);
        }

        $candidates = array_column($import->images ?? [], 'url');
        $imageUrls = array_values(array_intersect($imageUrls, $candidates));

        if ($existing === null) {
            $this->entitlements->ensureCanAdd($import->workspace_id, Entitlement::Properties);
        }

        $property = DB::transaction(function () use ($import, $user, $details, $imageUrls, $existing): Property {
            $property = $existing;

            if ($property === null) {
                $property = new Property([...$details, 'status' => PropertyStatus::Draft, 'metadata' => ['source' => ['url' => $import->url, 'imported_at' => now()->toIso8601String()]]]);
                $property->workspace_id = $import->workspace_id;
                $property->created_by = $user->id;
                $property->save();
            }

            $import->property_id = $property->id;
            $import->fill([
                'status' => $imageUrls === [] ? 'completed' : 'importing',
                'details' => $details,
                'selected_images' => $imageUrls,
                'rights_confirmed_at' => now(),
            ])->save();

            return $property;
        });

        $this->activity->log($import->workspace_id, $user, 'property.imported', "Imported {$property->name} from {$import->source_host}", $property, ['url' => $import->url]);

        if ($imageUrls !== []) {
            ImportListingImages::dispatch($import)->afterCommit();
        }

        return $property;
    }

    /**
     * Copy one chunk of the selected photos into the media library (queued
     * job). Returns true when more photos remain for another chunk.
     */
    public function importImages(PropertyImport $import, int $offset = 0, int $limit = PHP_INT_MAX): bool
    {
        $import->refresh()->loadMissing(['workspace', 'user', 'property']);

        if ($import->status !== 'importing' || $import->user === null) {
            return false;
        }

        $selected = array_slice($import->selected_images ?? [], 0, (int) config('import.max_images', 30));
        $imported = 0;
        $failures = $import->error === null || $offset === 0 ? [] : explode("\n", $import->error);

        foreach (array_slice($selected, $offset, $limit) as $url) {
            try {
                $asset = $this->importImage($import, $url);
                $imported++;

                if ($import->property && $import->property->cover_asset_id === null) {
                    $import->property->update(['cover_asset_id' => $asset->id]);
                }
            } catch (ImportException|ValidationException $exception) {
                $failures[] = Str::limit(basename((string) parse_url($url, PHP_URL_PATH)), 60).': '.$exception->getMessage();
            } catch (Throwable $exception) {
                report($exception);
                $failures[] = Str::limit(basename((string) parse_url($url, PHP_URL_PATH)), 60).': '.__('could not be copied.');
            }
        }

        $more = $offset + $limit < count($selected);

        $import->update([
            'status' => $more ? 'importing' : 'completed',
            'imported_count' => ($offset === 0 ? 0 : $import->imported_count) + $imported,
            'failed_count' => count($failures),
            'error' => $failures === [] ? null : Str::limit(implode("\n", $failures), 4000),
        ]);

        if (! $more && $import->virtual_tour_id !== null) {
            app(TourBuilder::class)->importFinished($import->refresh());
        }

        return $more;
    }

    protected function importImage(PropertyImport $import, string $url): MediaAsset
    {
        if (! $this->robots->allows($url)) {
            throw new ImportException(__('blocked by the image host’s robots.txt.'));
        }

        $file = $this->client->fetch($url, self::IMAGE_TYPES, (int) config('import.max_image_bytes'), 'image/avif;q=0,image/webp,image/jpeg,image/png');

        try {
            $size = @getimagesize($file->path);

            if ($size === false || ! in_array($size['mime'], self::IMAGE_TYPES, true)) {
                throw new ImportException(__('is not a valid JPEG, PNG or WebP image.'));
            }

            if ($size[0] < (int) config('import.min_image_width', 600)) {
                throw new ImportException(__('is too small (:width px wide).', ['width' => $size[0]]));
            }

            $this->entitlements->ensureCanAdd($import->workspace_id, Entitlement::StorageMb, $file->bytes);

            $extension = match ($size['mime']) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $named = TemporaryFiles::path($extension);
            rename($file->path, $named);
            $file = new FetchedResource($file->url, $file->contentType, $named, $file->bytes);

            $stored = $this->storage->uploadFile($named, $import->workspace->storageFolder().'/imports', 'image');
            $filename = Str::slug(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME) ?: 'photo').'.'.$extension;

            return $this->library->registerImported($import->workspace, $import->user, $stored, $import->property_id, $filename, [
                'url' => $url,
                'host' => parse_url($url, PHP_URL_HOST),
                'page' => $import->url,
                'import_id' => $import->ulid,
                'rights_confirmed_at' => $import->rights_confirmed_at?->toIso8601String(),
            ]);
        } finally {
            TemporaryFiles::delete($file->path);
        }
    }
}
