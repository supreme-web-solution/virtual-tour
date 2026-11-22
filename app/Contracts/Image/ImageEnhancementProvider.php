<?php

namespace App\Contracts\Image;

use App\Services\Image\ImageEditRequest;
use App\Services\Image\ImageEditResult;

/**
 * Edits an existing photo (clean-up, lighting, widening). The original is
 * never changed: the result is a new image written to a temporary file.
 */
interface ImageEnhancementProvider
{
    public function name(): string;

    public function model(): string;

    public function isConfigured(): bool;

    public function enhance(ImageEditRequest $request): ImageEditResult;
}
