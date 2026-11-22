<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\AiGeneration;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGeneration>
 */
class AiGenerationFactory extends Factory
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
            'generation_type' => GenerationType::TextToPanorama,
            'provider' => 'skybox',
            'status' => GenerationStatus::Queued,
            'input_payload' => ['title' => 'Living Room', 'prompt' => 'A bright living room', 'style_id' => 155, 'key' => 'living_room', 'connections' => []],
        ];
    }

    /**
     * A finished scene plan with the given scenes.
     *
     * @param  list<array<string, mixed>>  $scenes
     */
    public function completedPlan(array $scenes): static
    {
        return $this->state(fn (): array => [
            'generation_type' => GenerationType::ScenePlan,
            'provider' => 'openai',
            'status' => GenerationStatus::Completed,
            'input_payload' => ['description' => 'A modern apartment', 'scene_count' => count($scenes)],
            'output_metadata' => ['plan' => ['tour_title' => 'Modern Apartment', 'tour_description' => 'A tour', 'scenes' => $scenes]],
        ]);
    }
}
