<?php

namespace Database\Factories;

use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Models\MediaAsset;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $publicId = 'virtualtour/test/'.Str::lower(Str::random(16));

        return [
            'workspace_id' => Workspace::factory(),
            'asset_type' => AssetType::Photo,
            'source_type' => AssetSource::Upload,
            'original_filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'format' => 'jpg',
            'file_size' => fake()->numberBetween(200_000, 5_000_000),
            'width' => 4000,
            'height' => 3000,
            'cloudinary_public_id' => $publicId,
            'cloudinary_resource_type' => 'image',
            'secure_url' => "https://res.cloudinary.com/demo/image/upload/{$publicId}.jpg",
            'thumbnail_url' => "https://res.cloudinary.com/demo/image/upload/c_fill,w_480,h_320/{$publicId}.jpg",
        ];
    }

    /**
     * An equirectangular 360° panorama.
     */
    public function panorama(): static
    {
        return $this->state(fn (): array => [
            'asset_type' => AssetType::Panorama,
            'width' => 8000,
            'height' => 4000,
            'metadata' => ['panorama' => ['is_equirectangular' => true, 'ratio' => 2.0, 'warnings' => []]],
        ]);
    }
}
