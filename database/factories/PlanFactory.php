<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'price_monthly' => 2900,
            'currency' => 'USD',
            'entitlements' => config('plans.unlimited'),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * The plan workspaces get when they have no subscription.
     */
    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true, 'price_monthly' => 0]);
    }

    /**
     * Override some entitlements, keeping the rest unlimited.
     *
     * @param  array<string, int|bool|null>  $entitlements
     */
    public function with(array $entitlements): static
    {
        return $this->state(fn (): array => ['entitlements' => [...config('plans.unlimited'), ...$entitlements]]);
    }
}
