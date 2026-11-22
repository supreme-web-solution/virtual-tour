<?php

namespace App\Services\Video\Render;

/**
 * Everything needed to assemble a final video from local files.
 */
final readonly class RenderSpec
{
    /**
     * @param  list<RenderClip>  $clips  In playback order.
     */
    public function __construct(
        public string $workingDirectory,
        public int $width,
        public int $height,
        public array $clips,
        public string $outputPath,
        public string $posterPath,
        public bool $keepClipAudio = false,
        public ?RenderAudio $music = null,
        public ?RenderAudio $voiceover = null,
        public ?string $outroLogoPath = null,
        public float $outroSeconds = 2.5,
        public int $fps = 30,
    ) {}

    /**
     * Seconds of overlap for the transition leading out of a clip.
     */
    public static function transitionSeconds(string $transition): float
    {
        return match ($transition) {
            'crossfade' => 0.6,
            'fade_black' => 0.8,
            default => 0.05,
        };
    }

    /**
     * Length of the final video after transitions overlap.
     */
    public function totalSeconds(): float
    {
        $segments = $this->segments();
        $total = array_sum(array_column($segments, 'duration'));

        for ($index = 0; $index < count($segments) - 1; $index++) {
            $total -= self::transitionSeconds($segments[$index]['transition']);
        }

        return round($total, 3);
    }

    /**
     * Clips plus the optional outro, as duration/transition pairs.
     *
     * @return list<array{duration: float, transition: string}>
     */
    public function segments(): array
    {
        $segments = array_map(
            fn (RenderClip $clip): array => ['duration' => $clip->duration, 'transition' => $clip->transition],
            $this->clips,
        );

        if ($this->outroLogoPath !== null) {
            $segments[] = ['duration' => $this->outroSeconds, 'transition' => 'cut'];
        }

        return $segments;
    }
}
