<?php

namespace Database\Factories;

use App\Enums\AreaUnit;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
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
            'name' => fake()->streetName().' Residence',
            'property_type' => fake()->randomElement(PropertyType::cases()),
            'status' => PropertyStatus::Draft,
            'description' => fake()->paragraph(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country' => fake()->country(),
            'bedrooms' => fake()->numberBetween(1, 6),
            'bathrooms' => fake()->numberBetween(1, 4),
            'area' => fake()->numberBetween(60, 600),
            'area_unit' => AreaUnit::SquareMetres,
        ];
    }

    /**
     * Place the property in the given workspace, created by the given user.
     */
    public function inWorkspace(Workspace $workspace, ?int $createdBy = null): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
            'created_by' => $createdBy ?? $workspace->owner_id,
        ]);
    }
}
