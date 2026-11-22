<?php

namespace App\Services\Panorama;

final readonly class PanoramaStyle
{
    public function __construct(
        public int $id,
        public string $name,
        public string $model,
        public int $maxPromptLength,
        public int $maxNegativeLength,
        public bool $supportsImageToPanorama,
        public ?string $previewUrl = null,
    ) {}

    /**
     * @return array{id: int, name: string, model: string, max_prompt_length: int, max_negative_length: int, supports_image_to_panorama: bool, preview_url: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'model' => $this->model,
            'max_prompt_length' => $this->maxPromptLength,
            'max_negative_length' => $this->maxNegativeLength,
            'supports_image_to_panorama' => $this->supportsImageToPanorama,
            'preview_url' => $this->previewUrl,
        ];
    }
}
