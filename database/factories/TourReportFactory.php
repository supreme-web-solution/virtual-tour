<?php

namespace Database\Factories;

use App\Models\TourReport;
use App\Models\VirtualTour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TourReport>
 */
class TourReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'virtual_tour_id' => VirtualTour::factory(),
            'reason' => fake()->randomElement(array_keys(TourReport::REASONS)),
            'details' => fake()->optional()->sentence(),
        ];
    }
}
