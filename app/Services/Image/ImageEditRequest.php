<?php

namespace App\Services\Image;

final readonly class ImageEditRequest
{
    /**
     * @param  string  $imagePath  Local JPEG/PNG/WebP file to edit.
     * @param  string  $size  "1024x1024", "1536x1024" or "1024x1536".
     * @param  string  $quality  "low", "medium" or "high".
     */
    public function __construct(
        public string $imagePath,
        public string $prompt,
        public string $size = '1536x1024',
        public string $quality = 'medium',
    ) {}
}
