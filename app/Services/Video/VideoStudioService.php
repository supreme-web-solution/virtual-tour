<?php

namespace App\Services\Video;

use App\Contracts\Media\MediaStorage;
use App\Contracts\Music\MusicGenerationProvider;
use App\Contracts\Video\VideoGenerationProvider;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\SoundtrackMode;
use App\Enums\VideoStatus;
use App\Jobs\Video\AssembleVideo;
use App\Jobs\Video\GenerateMusicTrack;
use App\Jobs\Video\GenerateVideoClip;
use App\Jobs\Video\GenerateVideoStoryboard;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\VideoExport;
use App\Models\VideoProject;
use App\Models\VideoShot;
use App\Services\ActivityLogger;
use App\Services\AI\GenerationLifecycle;
use App\Services\Credits\CreditService;
use App\Services\Media\StoredAsset;
use App\Services\Music\MusicTrackResult;
use App\Support\TemporaryFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates a video project: storyboard → clips (+ music) → render.
 * Each clip is its own generation, so one shot can be regenerated without
 * touching the others, and credits are only charged for stored outputs.
 */
class VideoStudioService
{
    /**
     * Appended to every clip prompt to keep the property accurate.
     */
    public const PRESERVATION = 'Keep the exact architecture, room layout, furniture, materials, windows, walls and doors from the image. Realistic proportions, smooth natural camera motion, no new objects, no people, no text, no morphing or structural changes.';

    public function __construct(
        protected VideoGenerationProvider $videoProvider,
        protected MusicGenerationProvider $musicProvider,
        protected MediaStorage $storage,
        protected CreditService $credits,
        protected GenerationLifecycle $lifecycle,
        protected ActivityLogger $activity,
    ) {}

    /**
     * Create the project and queue its AI storyboard.
     *
     * @param  array<string, mixed>  $data  Validated project settings.
     * @param  list<int>  $sourceAssetIds  Media asset ids in the chosen order.
     */
    public function create(array $data, array $sourceAssetIds, User $user): VideoProject
    {
        $project = DB::transaction(function () use ($data, $sourceAssetIds, $user): VideoProject {
            $project = new VideoProject([
                'title' => $data['title'],
                'property_id' => $data['property_id'] ?? null,
                'virtual_tour_id' => $data['virtual_tour_id'] ?? null,
                'style' => $data['style'],
                'aspect_ratio' => $data['aspect_ratio'],
                'requested_duration' => $data['duration'],
                'resolution' => $this->videoProvider->capabilities()['resolution'],
                'provider' => $this->videoProvider->name(),
                'model' => $this->videoProvider->model(),
                'soundtrack_mode' => $data['soundtrack_mode'],
                'soundtrack_asset_id' => $data['soundtrack_asset_id'] ?? null,
                'voiceover_asset_id' => $data['voiceover_asset_id'] ?? null,
                'status' => VideoStatus::Planning,
                'settings' => $data['settings'] ?? [],
            ]);
            $project->workspace_id = (int) $user->current_workspace_id;
            $project->created_by = $user->id;
            $project->save();

            $generation = $this->newGeneration($project, $user, GenerationType::VideoStoryboard, 'openai', [
                'source_asset_ids' => $sourceAssetIds,
            ]);
            $this->credits->reserve($generation);

            return $project;
        });

        $storyboard = $project->generations()->latest('id')->firstOrFail();
        $this->lifecycle->dispatchOrFail($storyboard, function () use ($storyboard): void {
            GenerateVideoStoryboard::dispatch($storyboard)->afterCommit();
        });

        $this->activity->log($project->workspace_id, $user, 'video.created', "Created video {$project->title}", $project);

        return $project;
    }

    /**
     * Turn a validated storyboard into shots.
     *
     * @param  array<string, mixed>  $storyboard
     */
    public function applyStoryboard(VideoProject $project, array $storyboard): void
    {
        DB::transaction(function () use ($project, $storyboard): void {
            $assets = MediaAsset::where('workspace_id', $project->workspace_id)
                ->whereIn('ulid', array_column($storyboard['shots'], 'source_id'))
                ->pluck('id', 'ulid');

            $project->shots()->delete();

            foreach (array_values($storyboard['shots']) as $index => $shot) {
                if (! isset($assets[$shot['source_id']])) {
                    continue;
                }

                $project->shots()->create([
                    'source_asset_id' => $assets[$shot['source_id']],
                    'sort_order' => $index + 1,
                    'duration' => $shot['duration'],
                    'camera_movement' => $shot['camera_movement'],
                    'scene_name' => $shot['scene_name'] ?: null,
                    'prompt' => $shot['prompt'],
                    'transition' => $shot['transition'],
                    'status' => 'pending',
                    'metadata' => [
                        'visual_description' => $shot['visual_description'] ?? null,
                        'audio_notes' => $shot['audio_notes'] ?? null,
                    ],
                ]);
            }

            $project->storyboard = collect($storyboard)->except('shots')->all();
            $project->status = VideoStatus::Draft;
            $project->save();
        });
    }

    /**
     * Generate clips for the given shots (default: every shot without a clip),
     * plus AI music when the project needs it. All credits are held together.
     *
     * @param  list<int>|null  $shotIds
     * @return list<AiGeneration>
     */
    public function generateClips(VideoProject $project, User $user, ?array $shotIds = null): array
    {
        if (! $this->videoProvider->isConfigured()) {
            throw ValidationException::withMessages(['clips' => __('AI video is not configured. An administrator must add FAL_KEY.')]);
        }

        $generations = DB::transaction(function () use ($project, $user, $shotIds): array {
            $shots = $project->shots()
                ->when($shotIds !== null, fn ($query) => $query->whereIn('id', $shotIds))
                ->when($shotIds === null, fn ($query) => $query->whereIn('status', ['pending', 'failed']))
                ->where('status', '!=', 'generating')
                ->with('sourceAsset')
                ->get();

            if ($shots->isEmpty()) {
                throw ValidationException::withMessages(['clips' => __('There are no shots waiting for a clip.')]);
            }

            $created = [];

            foreach ($shots as $shot) {
                $generation = $this->newGeneration($project, $user, GenerationType::VideoClip, $this->videoProvider->name(), [
                    'shot_id' => $shot->ulid,
                    'title' => $shot->scene_name ?? 'Shot '.$shot->sort_order,
                    'duration' => $shot->duration,
                    'aspect_ratio' => $project->aspect_ratio,
                    'prompt' => $this->clipPrompt($project, $shot),
                    'image_url' => $this->sourceImageUrl($project, $shot->sourceAsset),
                    // Natural sound is generated with the clip only for ambient-sound videos.
                    'audio' => $project->soundtrack_mode === SoundtrackMode::Ambient,
                ]);
                $generation->model = $this->videoProvider->model();
                $generation->save();

                $shot->update(['status' => 'generating', 'generation_id' => $generation->id]);
                $created[] = $generation;
            }

            if ($this->needsMusic($project)) {
                $created[] = $this->newMusicGeneration($project, $user);
            }

            $this->credits->reserveFor($created);

            $project->update(['status' => VideoStatus::GeneratingClips]);

            return $created;
        });

        try {
            $this->lifecycle->dispatchOrFail($generations, function () use ($generations): void {
                foreach ($generations as $generation) {
                    $generation->generation_type === GenerationType::MusicTrack
                        ? GenerateMusicTrack::dispatch($generation)->afterCommit()
                        : GenerateVideoClip::dispatch($generation)->afterCommit();
                }
            });
        } catch (ValidationException $exception) {
            // Nothing will run: the shots can be tried again.
            $project->shots()->where('status', 'generating')->update(['status' => 'failed']);
            $project->update(['status' => VideoStatus::Draft]);

            throw $exception;
        }

        return $generations;
    }

    /**
     * (Re)generate only the soundtrack.
     */
    public function generateMusic(VideoProject $project, User $user, ?string $prompt = null): AiGeneration
    {
        if (! $this->musicProvider->isConfigured()) {
            throw ValidationException::withMessages(['music' => __('AI music is not configured. An administrator must add FAL_KEY.')]);
        }

        $generation = DB::transaction(function () use ($project, $user, $prompt): AiGeneration {
            $generation = $this->newMusicGeneration($project, $user, $prompt);
            $this->credits->reserve($generation);

            return $generation;
        });

        $this->lifecycle->dispatchOrFail($generation, function () use ($generation): void {
            GenerateMusicTrack::dispatch($generation)->afterCommit();
        });

        return $generation;
    }

    /**
     * Copy a generated clip into permanent storage and attach it to its shot.
     */
    public function storeClip(AiGeneration $generation, VideoClipResult $result): void
    {
        try {
            $generation->refresh();

            if ($generation->status->isFinal()) {
                return;
            }

            $project = $generation->videoProject;
            $shot = VideoShot::where('ulid', $generation->input('shot_id'))->first();

            if (! $project || ! $shot) {
                $this->lifecycle->fail($generation, 'The shot no longer exists.');

                return;
            }

            $this->lifecycle->advance($generation, GenerationStatus::Uploading);
            $stored = $this->storage->uploadFile($result->path, $project->workspace->storageFolder().'/video-clips', 'video');

            DB::transaction(function () use ($generation, $project, $shot, $stored, $result): void {
                $asset = $this->createAsset($project, $generation, AssetType::Video, $stored, Str::slug($shot->scene_name ?? 'shot').'-clip.mp4');
                $generation->outputs()->create(['media_asset_id' => $asset->id, 'output_type' => 'video_clip']);
                $generation->mergeOutput(['media_asset_id' => $asset->ulid]);
                $generation->actual_cost = $result->costUsd;
                $generation->provider_request_id = $result->providerRequestId;
                $generation->save();

                // A newer attempt for this shot wins; an older one is kept only in history.
                if ($shot->generation_id === $generation->id) {
                    $shot->update(['status' => 'completed', 'output_asset_id' => $asset->id]);
                }
            });

            $this->lifecycle->complete($generation);
            $this->refreshStatus($project);
        } finally {
            TemporaryFiles::delete($result->path);
        }
    }

    public function failClip(AiGeneration $generation, string $message): void
    {
        $this->lifecycle->fail($generation, $message);
        $shot = VideoShot::where('ulid', $generation->input('shot_id'))->first();

        if ($shot && $shot->generation_id === $generation->id) {
            $shot->update(['status' => 'failed', 'metadata' => array_merge($shot->metadata ?? [], ['error' => $message])]);
        }

        if ($generation->videoProject) {
            $this->refreshStatus($generation->videoProject);
        }
    }

    public function storeMusic(AiGeneration $generation, MusicTrackResult $result): void
    {
        try {
            $generation->refresh();

            if ($generation->status->isFinal() || ! $generation->videoProject) {
                return;
            }

            $project = $generation->videoProject;
            $this->lifecycle->advance($generation, GenerationStatus::Uploading);
            $stored = $this->storage->uploadFile($result->path, $project->workspace->storageFolder().'/music', 'video');

            DB::transaction(function () use ($generation, $project, $stored, $result): void {
                $asset = $this->createAsset($project, $generation, AssetType::Audio, $stored, Str::slug($project->title).'-music.'.($stored->format ?: 'mp3'));
                $generation->outputs()->create(['media_asset_id' => $asset->id, 'output_type' => 'music']);
                $generation->mergeOutput(['media_asset_id' => $asset->ulid]);
                $generation->actual_cost = $result->costUsd;
                $generation->save();

                $project->update(['soundtrack_asset_id' => $asset->id]);
            });

            $this->lifecycle->complete($generation);
            $this->refreshStatus($project);
        } finally {
            TemporaryFiles::delete($result->path);
        }
    }

    /**
     * Queue the final render.
     */
    public function render(VideoProject $project, User $user): VideoExport
    {
        $this->refreshStatus($project);

        if (! in_array($project->status, [VideoStatus::ReadyToRender, VideoStatus::Completed], true)) {
            throw ValidationException::withMessages(['render' => __('Every shot needs a finished clip (and the music must be ready) before rendering.')]);
        }

        $export = new VideoExport(['requested_by' => $user->id, 'status' => 'queued']);
        $export->video_project_id = $project->id;
        $export->save();

        $project->update(['status' => VideoStatus::Rendering]);
        AssembleVideo::dispatch($export)->afterCommit();

        return $export;
    }

    /**
     * Derive the project status from its shots, music and exports.
     */
    public function refreshStatus(VideoProject $project): void
    {
        $project->refresh();

        if (in_array($project->status, [VideoStatus::Planning, VideoStatus::Rendering], true)) {
            return;
        }

        $shots = $project->shots()->get(['status']);
        $musicPending = $this->musicActive($project);

        $status = match (true) {
            $shots->isEmpty() => VideoStatus::Draft,
            $shots->contains('status', 'generating') || $musicPending => VideoStatus::GeneratingClips,
            $shots->every(fn (VideoShot $shot): bool => $shot->status === 'completed') && ! $this->needsMusic($project) => $project->final_asset_id ? VideoStatus::Completed : VideoStatus::ReadyToRender,
            default => VideoStatus::Draft,
        };

        // Editing a finished video (new clip/music) makes it renderable again, not "completed".
        if ($status === VideoStatus::Completed && $project->status !== VideoStatus::Completed) {
            $status = VideoStatus::ReadyToRender;
        }

        $project->update(['status' => $status]);
    }

    public function clipPrompt(VideoProject $project, VideoShot $shot): string
    {
        $sound = $project->soundtrack_mode === SoundtrackMode::Ambient
            ? 'Sound: natural '.str_replace('_', ' ', (string) $project->settings()['ambience']).' ambience only, no music, no speech.'
            : 'Sound: none needed, no music, no speech.';

        return trim("{$shot->prompt}\nCamera: {$shot->camera_movement}.\n".self::PRESERVATION."\n{$sound}");
    }

    public function needsMusic(VideoProject $project): bool
    {
        return $project->soundtrack_mode === SoundtrackMode::AiMusic
            && $project->soundtrack_asset_id === null
            && ! $this->musicActive($project);
    }

    protected function musicActive(VideoProject $project): bool
    {
        return $project->generations()
            ->where('generation_type', GenerationType::MusicTrack)
            ->whereIn('status', GenerationStatus::activeValues())
            ->exists();
    }

    protected function sourceImageUrl(VideoProject $project, MediaAsset $asset): string
    {
        return $asset->asset_type === AssetType::Panorama
            ? $this->storage->panoramaViewUrl($asset->cloudinary_public_id, $project->aspect_ratio === '9:16' ? '9:16' : '16:9', 1280)
            : $this->storage->scaledImageUrl($asset->cloudinary_public_id, 1280);
    }

    protected function newMusicGeneration(VideoProject $project, User $user, ?string $prompt = null): AiGeneration
    {
        $settings = $project->settings();
        $brief = $prompt ?: ($project->storyboard['music_prompt'] ?? null);

        $text = implode(' ', array_filter([
            'Instrumental only, no vocals.',
            'Background music for a '.$project->requested_duration.'-second '.$project->style->label().' property video.',
            'Mood: '.$settings['music_mood'].'.',
            $brief ? "Direction: {$brief}" : null,
            'Clean, polished production that sits well under narration, with a natural ending.',
        ]));

        $generation = $this->newGeneration($project, $user, GenerationType::MusicTrack, $this->musicProvider->name(), [
            'title' => 'Soundtrack',
            // Long enough for every shot plus the logo outro.
            'seconds' => max($project->requested_duration, (int) $project->shots()->sum('duration')) + 3,
            'prompt' => $text,
        ]);
        $generation->model = $this->musicProvider->model();
        $generation->save();

        return $generation;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function newGeneration(VideoProject $project, User $user, GenerationType $type, string $provider, array $input): AiGeneration
    {
        $generation = new AiGeneration([
            'user_id' => $user->id,
            'property_id' => $project->property_id,
            'video_project_id' => $project->id,
            'generation_type' => $type,
            'provider' => $provider,
            'status' => GenerationStatus::Queued,
            'input_payload' => $input,
        ]);
        $generation->workspace_id = $project->workspace_id;
        $generation->save();

        return $generation;
    }

    protected function createAsset(VideoProject $project, AiGeneration $generation, AssetType $type, StoredAsset $stored, string $filename): MediaAsset
    {
        $asset = new MediaAsset([
            'property_id' => $project->property_id,
            'uploaded_by' => $generation->user_id,
            'asset_type' => $type,
            'source_type' => AssetSource::Generated,
            'original_filename' => $filename,
            'mime_type' => $stored->mimeType(),
            'format' => $stored->format,
            'file_size' => $stored->bytes,
            'width' => $stored->width,
            'height' => $stored->height,
            'duration' => $stored->duration,
            'cloudinary_public_id' => $stored->publicId,
            'cloudinary_resource_type' => $stored->resourceType,
            'secure_url' => $stored->secureUrl,
            'thumbnail_url' => $this->storage->thumbnailUrl($stored),
            'provider' => $generation->provider,
            'generation_id' => $generation->id,
            'metadata' => ['ai' => ['synthetic' => true, 'type' => $generation->generation_type->value, 'video_project' => $project->ulid]],
        ]);
        $asset->workspace_id = $project->workspace_id;
        $asset->save();

        return $asset;
    }
}
