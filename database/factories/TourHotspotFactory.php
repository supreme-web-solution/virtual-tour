<?php

namespace Database\Factories;

use App\Enums\HotspotType;
use App\Models\TourHotspot;
use App\Models\TourScene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TourHotspot>
 */
class TourHotspotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tour_scene_id' => TourScene::factory(),
            'type' => HotspotType::Info,
            'yaw' => fake()->randomFloat(3, -180, 180),
            'pitch' => fake()->randomFloat(3, -30, 30),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
        ];
    }

    public function navigation(TourScene $target): static
    {
        return $this->state(fn (): array => [
            'type' => HotspotType::Navigation,
            'target_scene_id' => $target->id,
            'title' => $target->title,
        ]);
    }
}
