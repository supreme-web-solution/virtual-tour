<?php

namespace App\Services\Panorama;

/**
 * Checks whether image dimensions describe an equirectangular 360° panorama.
 * Problems are reported as warnings; the original file is never altered.
 */
class PanoramaValidationService
{
    /**
     * Accepted deviation from an exact 2:1 ratio (2%).
     */
    public const RATIO_TOLERANCE = 0.02;

    /**
     * Below this width, panoramas look noticeably soft in the viewer.
     */
    public const MIN_RECOMMENDED_WIDTH = 4000;

    /**
     * Above this width, many mobile GPUs cannot load the texture in one piece.
     */
    public const MAX_SINGLE_TEXTURE_WIDTH = 16384;

    /**
     * @return array{is_equirectangular: bool, ratio: float|null, warnings: list<string>}
     */
    public function inspect(?int $width, ?int $height): array
    {
        if (! $width || ! $height) {
            return [
                'is_equirectangular' => false,
                'ratio' => null,
                'warnings' => ['The image dimensions could not be read.'],
            ];
        }

        $ratio = round($width / $height, 4);
        $isEquirectangular = abs($ratio - 2.0) <= 2.0 * self::RATIO_TOLERANCE;
        $warnings = [];

        if (! $isEquirectangular) {
            $warnings[] = "The image is {$width}×{$height} (ratio {$ratio}:1). A 360° equirectangular panorama should be 2:1, so it may look distorted in the viewer.";
        }

        if ($width < self::MIN_RECOMMENDED_WIDTH) {
            $warnings[] = 'The panorama is narrower than '.self::MIN_RECOMMENDED_WIDTH.' px and may look blurry when zoomed in.';
        }

        if ($width > self::MAX_SINGLE_TEXTURE_WIDTH) {
            $warnings[] = 'The panorama is wider than '.self::MAX_SINGLE_TEXTURE_WIDTH.' px. Some mobile devices may display a reduced-resolution version.';
        }

        return [
            'is_equirectangular' => $isEquirectangular,
            'ratio' => $ratio,
            'warnings' => $warnings,
        ];
    }
}
