<?php

namespace Tests\Fakes;

use App\Contracts\Music\MusicGenerationProvider;
use App\Exceptions\ProviderException;
use App\Services\Music\MusicTrackResult;
use App\Support\TemporaryFiles;

class FakeMusicProvider implements MusicGenerationProvider
{
    /** @var list<string> */
    public array $prompts = [];

    public ?ProviderException $error = null;

    public function __construct(public bool $configured = true) {}

    public function name(): string
    {
        return 'fal';
    }

    public function model(): string
    {
        return 'fal-ai/elevenlabs/music';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /** @var list<int|null> */
    public array $lengths = [];

    public function generate(string $prompt, ?int $seconds = null): MusicTrackResult
    {
        $this->prompts[] = $prompt;
        $this->lengths[] = $seconds;

        if ($this->error) {
            throw $this->error;
        }

        $path = TemporaryFiles::path('mp3');
        file_put_contents($path, 'fake-mp3');

        return new MusicTrackResult($path, 'audio/mpeg', 'music-'.count($this->prompts), 0.04);
    }
}
