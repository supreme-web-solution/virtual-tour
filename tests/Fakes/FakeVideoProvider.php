<?php

namespace Tests\Fakes;

use App\Contracts\Video\VideoGenerationProvider;
use App\Exceptions\ProviderException;
use App\Services\Video\VideoClipRequest;
use App\Services\Video\VideoClipResult;
use App\Support\TemporaryFiles;

class FakeVideoProvider implements VideoGenerationProvider
{
    /** @var list<VideoClipRequest> */
    public array $requests = [];

    public ?ProviderException $error = null;

    public function __construct(public bool $configured = true) {}

    public function name(): string
    {
        return 'fal';
    }

    public function model(): string
    {
        return 'fal-ai/kling-video/v3/pro/image-to-video';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function capabilities(): array
    {
        return [
            'min_seconds' => 3,
            'max_seconds' => 10,
            'aspect_ratios' => ['16:9', '9:16'],
            'resolution' => '720p',
            'negative_prompt' => false,
            'prompted_audio' => true,
        ];
    }

    public function generate(VideoClipRequest $request): VideoClipResult
    {
        $this->requests[] = $request;

        if ($this->error) {
            throw $this->error;
        }

        $path = TemporaryFiles::path('mp4');
        file_put_contents($path, 'fake-mp4');

        return new VideoClipResult($path, 'video/mp4', 'interaction-'.count($this->requests), 0.5);
    }
}
