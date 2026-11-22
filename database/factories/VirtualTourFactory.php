<?php

namespace Database\Factories;

use App\Enums\TourStatus;
use App\Enums\TourVisibility;
use App\Models\VirtualTour;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VirtualTour>
 */
class VirtualTourFactory extends Factory
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
            'title' => fake()->streetName().' Tour',
            'status' => TourStatus::Draft,
            'visibility' => TourVisibility::Unlisted,
        ];
    }

    public function inWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
        ]);
    }

    public function published(TourVisibility $visibility = TourVisibility::Public): static
    {
        return $this->state(fn (): array => [
            'status' => TourStatus::Published,
            'visibility' => $visibility,
            'published_at' => now(),
        ]);
    }
}
