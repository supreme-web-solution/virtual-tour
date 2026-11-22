<?php

use App\Services\Video\Render\FfmpegVideoRenderer;
use App\Services\Video\Render\RenderAudio;
use App\Services\Video\Render\RenderClip;
use App\Services\Video\Render\RenderSpec;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->renderer = app(FfmpegVideoRenderer::class);

    if (! $this->renderer->isAvailable()) {
        $this->markTestSkipped('FFmpeg is not installed.');
    }

    $this->directory = storage_path('framework/testing/renders/ffmpeg-'.uniqid());
    config(['ai.video.temp_path' => dirname($this->directory)]);
    File::ensureDirectoryExists($this->directory);
});

afterEach(function () {
    if (isset($this->directory)) {
        File::deleteDirectory(dirname($this->directory));
    }
});

/**
 * Make a short test-pattern clip with FFmpeg itself.
 */
function testClip(string $path, string $source): void
{
    (new Process([(string) config('services.ffmpeg.ffmpeg_path'), '-y', '-v', 'error', '-f', 'lavfi', '-i', $source, '-t', '3', '-pix_fmt', 'yuv420p', $path]))
        ->setTimeout(60)
        ->mustRun();
}

test('clips, transitions and music are rendered into one vertical video of the planned length', function () {
    testClip($this->directory.'/a.mp4', 'testsrc=size=1280x720:rate=30');
    testClip($this->directory.'/b.mp4', 'smptebars=size=1280x720:rate=30');
    testClip($this->directory.'/music.m4a', 'sine=frequency=440');

    $spec = new RenderSpec(
        workingDirectory: $this->directory,
        width: 720,
        height: 1280,
        clips: [
            new RenderClip($this->directory.'/a.mp4', 3, 'crossfade', false),
            new RenderClip($this->directory.'/b.mp4', 3, 'cut', false),
        ],
        outputPath: $this->directory.'/video.mp4',
        posterPath: $this->directory.'/poster.jpg',
        music: new RenderAudio($this->directory.'/music.m4a', volume: 0.7, fadeOut: 1, loop: true),
    );

    $result = $this->renderer->render($spec);

    expect($result->width)->toBe(720)
        ->and($result->height)->toBe(1280)
        ->and($result->hasAudio)->toBeTrue()
        ->and($result->duration)->toEqualWithDelta($spec->totalSeconds(), 0.2)
        ->and(filesize($result->posterPath))->toBeGreaterThan(0);
});

test('files outside the render folder are refused', function () {
    $spec = new RenderSpec(
        workingDirectory: $this->directory,
        width: 1280,
        height: 720,
        clips: [new RenderClip(base_path('composer.json'), 3, 'cut', false)],
        outputPath: $this->directory.'/video.mp4',
        posterPath: $this->directory.'/poster.jpg',
    );

    expect(fn () => $this->renderer->render($spec))->toThrow(RuntimeException::class);
});
