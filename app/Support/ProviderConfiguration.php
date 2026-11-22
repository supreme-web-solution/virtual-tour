<?php

namespace App\Support;

/**
 * Single source of truth for which external providers are configured, so
 * features can be enabled or disabled with a clear message instead of
 * failing at request time.
 */
class ProviderConfiguration
{
    public function cloudinary(): bool
    {
        return $this->filled('services.cloudinary.cloud_name', 'services.cloudinary.api_key', 'services.cloudinary.api_secret');
    }

    public function openAi(): bool
    {
        return $this->filled('services.openai.api_key');
    }

    public function skybox(): bool
    {
        return $this->filled('services.skybox.api_key');
    }

    public function fal(): bool
    {
        return $this->filled('services.fal.key');
    }

    /**
     * Feature flags safe to share with the browser (booleans only, never secrets).
     *
     * @return array{media_uploads: bool, ai_analysis: bool, panorama_generation: bool, video_generation: bool}
     */
    public function features(): array
    {
        return [
            'media_uploads' => $this->cloudinary(),
            'ai_analysis' => $this->openAi(),
            'panorama_generation' => $this->skybox(),
            'video_generation' => $this->fal(),
        ];
    }

    protected function filled(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (blank(config($key))) {
                return false;
            }
        }

        return true;
    }
}
