<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'owner_id' => User::factory(),
            'is_personal' => false,
        ];
    }

    /**
     * Register the owner as an Owner member once created.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Workspace $workspace): void {
            $workspace->addMember($workspace->owner, WorkspaceRole::Owner);
        });
    }
}
