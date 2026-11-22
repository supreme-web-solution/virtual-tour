<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\TourScene;
use App\Models\VirtualTour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TourScene>
 */
class TourSceneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'virtual_tour_id' => VirtualTour::factory(),
            'media_asset_id' => fn (array $attributes) => MediaAsset::factory()->panorama()->create([
                'workspace_id' => VirtualTour::whereKey($attributes['virtual_tour_id'])->value('workspace_id'),
            ])->id,
            'title' => fake()->randomElement(['Living Room', 'Kitchen', 'Bedroom', 'Balcony', 'Entrance']),
            'sort_order' => 0,
        ];
    }

    /**
     * Attach the scene to a tour, using a panorama from the same workspace.
     */
    public function forTour(VirtualTour $tour): static
    {
        return $this->state(fn (): array => ['virtual_tour_id' => $tour->id]);
    }
}
