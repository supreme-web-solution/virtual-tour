<?php

namespace Tests\Fakes;

use App\Contracts\Video\VideoRenderer;
use App\Services\Video\Render\RenderResult;
use App\Services\Video\Render\RenderSpec;

class FakeVideoRenderer implements VideoRenderer
{
    /** @var list<RenderSpec> */
    public array $specs = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function hasAudio(string $path): bool
    {
        return false;
    }

    public function render(RenderSpec $spec): RenderResult
    {
        $this->specs[] = $spec;
        file_put_contents($spec->outputPath, 'final-mp4');
        file_put_contents($spec->posterPath, 'poster-jpg');

        return new RenderResult($spec->outputPath, $spec->posterPath, $spec->totalSeconds(), $spec->width, $spec->height, $spec->music !== null);
    }
}
