<?php

namespace App\Jobs\Video;

use App\Contracts\Video\VideoGenerationProvider;
use App\Enums\GenerationStatus;
use App\Exceptions\ProviderException;
use App\Models\AiGeneration;
use App\Services\AI\GenerationLifecycle;
use App\Services\Video\VideoClipRequest;
use App\Services\Video\VideoStudioService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Generates one clip. The provider call is synchronous and can take
 * minutes, so this runs on the long-timeout connection.
 */
class GenerateVideoClip implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 700;

    public function __construct(public AiGeneration $generation)
    {
        $this->onConnection((string) config('queue.long_connection', 'redis-long'));
        $this->onQueue('video-generation');
    }

    public function uniqueId(): string
    {
        return (string) $this->generation->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(VideoGenerationProvider $provider, VideoStudioService $studio, GenerationLifecycle $lifecycle): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status->isFinal()) {
            return;
        }

        $lifecycle->advance($generation, GenerationStatus::Processing);

        try {
            $result = $provider->generate(new VideoClipRequest(
                prompt: (string) $generation->input('prompt'),
                imageUrl: (string) $generation->input('image_url'),
                durationSeconds: (int) $generation->input('duration'),
                aspectRatio: (string) $generation->input('aspect_ratio'),
                withAudio: (bool) $generation->input('audio', false),
            ));
        } catch (ProviderException $exception) {
            if ($exception->retryable && $this->attempts() < $this->tries) {
                throw $exception;
            }

            $studio->failClip($generation, $exception->getMessage());

            return;
        }

        $studio->storeClip($generation, $result);
    }

    public function failed(?Throwable $exception): void
    {
        app(VideoStudioService::class)->failClip($this->generation, $exception?->getMessage() ?? 'Clip generation failed.');
    }
}
