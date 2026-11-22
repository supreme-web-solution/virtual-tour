<?php

namespace App\Services\Video;

use App\Contracts\Media\MediaStorage;
use App\Contracts\Video\VideoRenderer;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\SoundtrackMode;
use App\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\VideoExport;
use App\Models\VideoProject;
use App\Models\VideoShot;
use App\Services\ActivityLogger;
use App\Services\Media\StoredAsset;
use App\Services\Video\Render\RenderAudio;
use App\Services\Video\Render\RenderClip;
use App\Services\Video\Render\RenderResult;
use App\Services\Video\Render\RenderSpec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Builds the final MP4 for an export: downloads clips and audio into a
 * private working folder, renders with the VideoRenderer, stores the result
 * and poster permanently, and removes the working folder.
 */
class VideoAssemblyService
{
    public function __construct(
        protected VideoRenderer $renderer,
        protected MediaStorage $storage,
        protected ActivityLogger $activity,
    ) {}

    public function assemble(VideoExport $export): void
    {
        $export->refresh();

        if (in_array($export->status, ['completed', 'failed'], true)) {
            return;
        }

        $project = $export->project()->with(['shots.outputAsset', 'soundtrack', 'voiceover', 'tour', 'workspace'])->firstOrFail();
        $directory = $this->workingDirectory($export);
        $export->update(['status' => 'rendering', 'started_at' => now()]);

        try {
            $result = $this->renderer->render($this->spec($project, $directory));
            $this->store($project, $export, $result);
            File::deleteDirectory($directory);
        } catch (Throwable $exception) {
            // The folder is kept for debugging and removed by `videos:clean-renders`.
            $this->fail($project, $export, $exception->getMessage());

            throw $exception;
        }
    }

    public function fail(VideoProject $project, VideoExport $export, string $message): void
    {
        $export->update(['status' => 'failed', 'error_message' => mb_substr($message, 0, 2000), 'completed_at' => now()]);
        $project->update(['status' => VideoStatus::ReadyToRender]);
    }

    /**
     * Delete working folders older than the retention period.
     */
    public function cleanOldRenders(int $hours): int
    {
        $base = (string) config('ai.video.temp_path');
        $removed = 0;

        if (! is_dir($base)) {
            return 0;
        }

        foreach (File::directories($base) as $directory) {
            if (filemtime($directory) < now()->subHours($hours)->getTimestamp()) {
                File::deleteDirectory($directory);
                $removed++;
            }
        }

        return $removed;
    }

    protected function spec(VideoProject $project, string $directory): RenderSpec
    {
        $shots = $project->shots;

        if ($shots->isEmpty() || $shots->contains(fn (VideoShot $shot): bool => $shot->outputAsset === null)) {
            throw new RuntimeException('Every shot needs a finished clip before rendering.');
        }

        $settings = $project->settings();
        [$width, $height] = $project->frameSize();

        $clips = array_values($shots->values()->map(function (VideoShot $shot, int $index) use ($directory): RenderClip {
            $path = $this->download((string) $shot->outputAsset?->secure_url, $directory, "clip-{$index}.mp4");

            return new RenderClip($path, (float) $shot->duration, $shot->transition, $this->renderer->hasAudio($path));
        })->all());

        $music = null;

        if (in_array($project->soundtrack_mode, [SoundtrackMode::AiMusic, SoundtrackMode::OwnAudio], true) && $project->soundtrack) {
            $music = new RenderAudio(
                path: $this->download($project->soundtrack->secure_url, $directory, 'music.'.($project->soundtrack->format ?: 'mp3')),
                volume: ((int) $settings['music_volume']) / 100,
                fadeIn: (float) $settings['audio_fade_in'],
                fadeOut: (float) $settings['audio_fade_out'],
                trimStart: (float) $settings['audio_trim_start'],
                loop: true,
            );
        }

        $voiceover = $project->voiceover
            ? new RenderAudio(
                path: $this->download($project->voiceover->secure_url, $directory, 'voice.'.($project->voiceover->format ?: 'mp3')),
                volume: ((int) $settings['voiceover_volume']) / 100,
            )
            : null;

        $logo = $settings['branded_outro'] ? $this->logoUrl($project) : null;

        return new RenderSpec(
            workingDirectory: $directory,
            width: $width,
            height: $height,
            clips: $clips,
            outputPath: $directory.DIRECTORY_SEPARATOR.'video.mp4',
            posterPath: $directory.DIRECTORY_SEPARATOR.'poster.jpg',
            keepClipAudio: $project->soundtrack_mode === SoundtrackMode::Ambient,
            music: $music,
            voiceover: $voiceover,
            outroLogoPath: $logo ? $this->download($logo, $directory, 'logo.png') : null,
        );
    }

    protected function store(VideoProject $project, VideoExport $export, RenderResult $result): void
    {
        $folder = $project->workspace->storageFolder().'/videos';
        $video = $this->storage->uploadFile($result->videoPath, $folder, 'video');
        $poster = $this->storage->uploadFile($result->posterPath, $folder, 'image');

        DB::transaction(function () use ($project, $export, $result, $video, $poster): void {
            $videoAsset = $this->asset($project, AssetType::Video, $video, Str::slug($project->title).'.mp4');
            $posterAsset = $this->asset($project, AssetType::Photo, $poster, Str::slug($project->title).'-poster.jpg');

            $export->update([
                'status' => 'completed',
                'media_asset_id' => $videoAsset->id,
                'poster_asset_id' => $posterAsset->id,
                'duration' => $result->duration,
                'width' => $result->width,
                'height' => $result->height,
                'completed_at' => now(),
                'settings' => ['soundtrack_mode' => $project->soundtrack_mode->value, 'aspect_ratio' => $project->aspect_ratio],
            ]);

            $project->update([
                'final_asset_id' => $videoAsset->id,
                'poster_asset_id' => $posterAsset->id,
                'status' => VideoStatus::Completed,
            ]);
        });

        $this->activity->log($project->workspace_id, null, 'video.rendered', "Rendered video {$project->title}", $project);
    }

    protected function asset(VideoProject $project, AssetType $type, StoredAsset $stored, string $filename): MediaAsset
    {
        $asset = new MediaAsset([
            'property_id' => $project->property_id,
            'uploaded_by' => $project->created_by,
            'asset_type' => $type,
            'source_type' => AssetSource::Derived,
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
            'provider' => 'ffmpeg',
            'metadata' => ['video_project' => $project->ulid],
        ]);
        $asset->workspace_id = $project->workspace_id;
        $asset->save();

        return $asset;
    }

    /**
     * The tour's logo, when the project is linked to a branded tour.
     */
    protected function logoUrl(VideoProject $project): ?string
    {
        $logoId = $project->tour?->branding()['logo_asset_id'] ?? null;

        if (! $logoId) {
            return null;
        }

        $logo = MediaAsset::where('workspace_id', $project->workspace_id)->where('ulid', $logoId)->first();

        return $logo ? $this->storage->scaledImageUrl($logo->cloudinary_public_id, 800) : null;
    }

    /**
     * Stream a remote file to disk (never into memory). Only HTTPS is allowed.
     */
    protected function download(string $url, string $directory, string $name): string
    {
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Media must be downloaded over HTTPS.');
        }

        $path = $directory.DIRECTORY_SEPARATOR.basename($name);
        $response = Http::timeout(120)->sink($path)->get($url);

        if ($response->failed() || ! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException("Could not download {$name}.");
        }

        return $path;
    }

    protected function workingDirectory(VideoExport $export): string
    {
        $base = (string) config('ai.video.temp_path');
        $directory = rtrim($base, '/\\').DIRECTORY_SEPARATOR.'export-'.$export->ulid;

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return realpath($directory) ?: $directory;
    }
}
