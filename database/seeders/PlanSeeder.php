<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Creates the starting plans from config/plans.php. Existing plans (matched
 * by slug) are left alone so admin edits are never overwritten.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('plans.defaults', []) as $index => $plan) {
            Plan::firstOrCreate(['slug' => $plan['slug']], [
                'name' => $plan['name'],
                'description' => $plan['description'] ?? null,
                'price_monthly' => $plan['price_monthly'],
                'currency' => config('plans.currency', 'USD'),
                'entitlements' => $plan['entitlements'],
                'is_default' => $plan['is_default'] ?? false,
                'is_active' => true,
                'sort_order' => $index,
            ]);
        }
    }
}
