<?php

namespace Database\Factories;

use App\Models\PropertyImport;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyImport>
 */
class PropertyImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'url' => 'https://listings.example.com/homes/lekki-villa',
            'source_host' => 'listings.example.com',
            'status' => 'queued',
        ];
    }

    /**
     * Fetched and waiting for the user to review.
     */
    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => 'ready',
            'page_title' => 'Lekki Garden Villa',
            'details' => [
                'name' => 'Lekki Garden Villa',
                'property_type' => 'villa',
                'description' => 'A four-bedroom villa with a garden and pool.',
                'city' => 'Lagos',
                'country' => 'Nigeria',
                'bedrooms' => 4,
                'bathrooms' => 4.5,
                'area' => 450,
                'area_unit' => 'sqm',
                'price' => 250000000,
                'currency' => 'NGN',
            ],
            'images' => [
                ['url' => 'https://cdn.example.com/photos/living.jpg', 'alt' => 'Living room'],
                ['url' => 'https://cdn.example.com/photos/kitchen.jpg', 'alt' => 'Kitchen'],
                ['url' => 'https://cdn.example.com/photos/pool.jpg', 'alt' => null],
            ],
        ]);
    }
}
