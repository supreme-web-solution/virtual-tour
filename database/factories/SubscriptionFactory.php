<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
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
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'provider' => 'manual',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'auto_renew' => true,
        ];
    }
}
