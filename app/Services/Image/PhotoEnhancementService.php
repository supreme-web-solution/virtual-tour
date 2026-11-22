<?php

namespace App\Services\Image;

use App\Contracts\Image\ImageEnhancementProvider;
use App\Contracts\Media\MediaStorage;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Exceptions\ProviderException;
use App\Jobs\Image\EnhancePhoto;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AI\GenerationLifecycle;
use App\Services\Billing\EntitlementService;
use App\Services\Credits\CreditService;
use App\Services\Media\StoredAsset;
use App\Support\TemporaryFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * AI photo studio: clean up, brighten or widen a property photo.
 *
 * The original is never touched; the result is saved as a new library asset
 * (source "derived") that points back at the original. Because a generative
 * model redraws the picture, every prompt forbids adding, removing or
 * changing anything about the property itself.
 */
class PhotoEnhancementService
{
    public const MODES = ['enhance', 'brighten', 'denoise', 'wide', 'upscale'];

    /**
     * Said in every prompt: the property must look exactly as it does.
     */
    public const PRESERVE = 'This is a real property photograph. Keep the architecture, room layout, furniture, fixtures, materials, colours of objects, windows, doors and everything visible exactly as they are. Do not add, remove, move or restyle anything. No people, no text, no watermarks, no logos, no new objects.';

    public function __construct(
        protected ImageEnhancementProvider $provider,
        protected FalUpscaleProvider $upscaler,
        protected MediaStorage $storage,
        protected CreditService $credits,
        protected GenerationLifecycle $lifecycle,
        protected EntitlementService $entitlements,
        protected ActivityLogger $activity,
    ) {}

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function modes(): array
    {
        return [
            ['value' => 'enhance', 'label' => 'Enhance', 'description' => 'Balanced clean-up: exposure, colour, sharpness and fine detail.'],
            ['value' => 'brighten', 'label' => 'Fix lighting', 'description' => 'Brighten dark rooms and balance bright windows, keeping it natural.'],
            ['value' => 'denoise', 'label' => 'Reduce noise', 'description' => 'Remove grain and compression artefacts from low-light or phone photos.'],
            ['value' => 'wide', 'label' => 'Widen', 'description' => 'Extend the view to a wide landscape frame. AI invents the extra edges.'],
            ['value' => 'upscale', 'label' => 'Upscale 4×', 'description' => 'Four times the pixels for large screens and print. Adds detail without redrawing the scene.'],
        ];
    }

    public function prompt(string $mode): string
    {
        $task = match ($mode) {
            'brighten' => 'Correct the lighting: lift shadows and brighten dark areas, recover blown-out windows, balance white balance and exposure so the room looks natural and well lit.',
            'denoise' => 'Remove noise, grain and compression artefacts and gently sharpen, while keeping all real texture and detail.',
            'wide' => 'Extend the scene sideways to a wide landscape frame by continuing the existing room or exterior naturally at the left and right edges, in the same style, lighting and perspective. Keep the original centre of the photo unchanged.',
            default => 'Professionally enhance this photo: correct exposure and white balance, improve colour, reduce noise, and sharpen fine detail.',
        };

        return $task.' '.self::PRESERVE;
    }

    /**
     * The edited photo's size: follows the source orientation (widening is always landscape).
     */
    public function size(MediaAsset $photo, string $mode): string
    {
        if ($mode === 'wide') {
            return '1536x1024';
        }

        $ratio = ($photo->width ?: 4) / max(1, $photo->height ?: 3);

        return match (true) {
            $ratio > 1.15 => '1536x1024',
            $ratio < 0.87 => '1024x1536',
            default => '1024x1024',
        };
    }

    public function credits(string $quality, string $mode = 'enhance'): int
    {
        if ($mode === 'upscale') {
            return (int) config('ai.operations.photo_upscale.credits');
        }

        return GenerationType::PhotoEnhance->credits() + ($quality === 'high' ? 2 : 0);
    }

    public function start(User $user, MediaAsset $photo, string $mode, string $quality): AiGeneration
    {
        if ($mode === 'upscale' ? ! $this->upscaler->isConfigured() : ! $this->provider->isConfigured()) {
            throw ValidationException::withMessages(['mode' => $mode === 'upscale'
                ? __('Upscaling is not set up yet. An administrator must add the fal.ai key.')
                : __('Photo enhancement is not set up yet. An administrator must add the OpenAI API key.')]);
        }

        if ($photo->asset_type !== AssetType::Photo || $photo->cloudinary_resource_type !== 'image') {
            throw ValidationException::withMessages(['mode' => __('Only photos can be enhanced. 360° panoramas must keep their exact shape.')]);
        }

        $generation = DB::transaction(function () use ($user, $photo, $mode, $quality): AiGeneration {
            $generation = new AiGeneration([
                'user_id' => $user->id,
                'property_id' => $photo->property_id,
                'generation_type' => GenerationType::PhotoEnhance,
                'provider' => $mode === 'upscale' ? $this->upscaler->name() : $this->provider->name(),
                'model' => $mode === 'upscale' ? $this->upscaler->model() : $this->provider->model(),
                'status' => GenerationStatus::Queued,
                'input_payload' => [
                    'source_asset_id' => $photo->ulid,
                    'mode' => $mode,
                    'quality' => $quality,
                    'credits' => $this->credits($quality, $mode),
                    'title' => Str::limit((string) pathinfo((string) $photo->original_filename, PATHINFO_FILENAME), 80, ''),
                ],
            ]);
            $generation->workspace_id = $photo->workspace_id;
            $generation->save();
            $this->credits->reserve($generation);

            return $generation;
        });

        $this->lifecycle->dispatchOrFail($generation, function () use ($generation): void {
            EnhancePhoto::dispatch($generation)->afterCommit();
        });

        return $generation;
    }

    /**
     * Run the edit (queued job): fetch the photo, edit it, store the result.
     */
    public function run(AiGeneration $generation): void
    {
        $generation->refresh();

        if ($generation->status->isFinal()) {
            return;
        }

        $source = MediaAsset::where('workspace_id', $generation->workspace_id)->where('ulid', $generation->input('source_asset_id'))->first();

        if ($source === null) {
            $this->lifecycle->fail($generation, 'The original photo no longer exists.');

            return;
        }

        $this->lifecycle->advance($generation, GenerationStatus::Processing);
        $original = null;
        $result = null;

        try {
            $mode = (string) $generation->input('mode', 'enhance');

            if ($mode === 'upscale') {
                $result = $this->upscaler->upscale($this->storage->scaledImageUrl($source->cloudinary_public_id, (int) config('ai.providers.fal.upscale_input_width')));
            } else {
                $original = $this->download($source);
                $result = $this->provider->enhance(new ImageEditRequest(
                    imagePath: $original,
                    prompt: $this->prompt($mode),
                    size: $this->size($source, $mode),
                    quality: in_array($generation->input('quality'), ['medium', 'high'], true) ? (string) $generation->input('quality') : 'medium',
                ));
            }

            $this->lifecycle->advance($generation, GenerationStatus::Uploading);
            $workspace = $source->workspace;
            $stored = $this->storage->uploadFile($result->path, $workspace->storageFolder().'/enhanced', 'image');
            $asset = $this->createAsset($generation, $source, $stored, $mode);

            $generation->refresh();
            $generation->actual_cost = $result->costUsd;
            $generation->mergeOutput(['media_asset_id' => $asset->ulid, 'width' => $result->width, 'height' => $result->height]);
            $generation->save();
            $generation->outputs()->create(['media_asset_id' => $asset->id, 'output_type' => 'enhanced_photo']);

            $this->lifecycle->complete($generation);
            $this->activity->log($generation->workspace_id, $generation->user, 'media.enhanced', "Enhanced photo {$source->original_filename}", $asset);
        } catch (ProviderException $exception) {
            // Temporary problems are retried by the job; anything else ends it now.
            if (! $exception->retryable) {
                $this->lifecycle->fail($generation, $exception->getMessage());
            }

            throw $exception;
        } finally {
            TemporaryFiles::delete($original);
            TemporaryFiles::delete($result?->path);
        }
    }

    protected function createAsset(AiGeneration $generation, MediaAsset $source, StoredAsset $stored, string $mode): MediaAsset
    {
        $suffix = ['enhance' => 'enhanced', 'brighten' => 'brightened', 'denoise' => 'denoised', 'wide' => 'wide', 'upscale' => '4x'][$mode] ?? 'enhanced';
        $base = Str::slug((string) pathinfo((string) $source->original_filename, PATHINFO_FILENAME)) ?: 'photo';

        $asset = new MediaAsset([
            'property_id' => $source->property_id,
            'uploaded_by' => $generation->user_id,
            'asset_type' => AssetType::Photo,
            'source_type' => AssetSource::Derived,
            'original_filename' => "{$base}-{$suffix}.jpg",
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
                'enhanced_from' => $source->ulid,
                'enhance_mode' => $mode,
                'ai' => ['synthetic' => true, 'type' => 'photo_enhance'],
            ],
        ]);
        $asset->workspace_id = $source->workspace_id;
        $asset->save();

        return $asset;
    }

    /**
     * The photo as a temporary JPEG, scaled to the largest size the editor accepts.
     */
    protected function download(MediaAsset $source): string
    {
        $url = $this->storage->scaledImageUrl($source->cloudinary_public_id, 2048);

        if (! str_starts_with($url, 'https://')) {
            throw new ProviderException('The photo could not be loaded.');
        }

        $path = TemporaryFiles::path('jpg');
        $response = Http::timeout(60)->sink($path)->get($url);

        if ($response->failed() || ! is_file($path) || filesize($path) < 1024) {
            TemporaryFiles::delete($path);

            throw new ProviderException('The photo could not be loaded.', retryable: true);
        }

        return $path;
    }
}
