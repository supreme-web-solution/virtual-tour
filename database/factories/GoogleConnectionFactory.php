<?php

namespace Database\Factories;

use App\Models\GoogleConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleConnection>
 */
class GoogleConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_email' => fake()->safeEmail(),
            'refresh_token' => fake()->sha256(),
        ];
    }
}
