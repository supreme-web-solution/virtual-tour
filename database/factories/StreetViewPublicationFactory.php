<?php

namespace Database\Factories;

use App\Models\StreetViewPublication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StreetViewPublication>
 */
class StreetViewPublicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => 'pending',
        ];
    }
}
