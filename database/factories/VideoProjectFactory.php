<?php

namespace Database\Factories;

use App\Enums\SoundtrackMode;
use App\Enums\VideoStatus;
use App\Enums\VideoStyle;
use App\Models\VideoProject;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoProject>
 */
class VideoProjectFactory extends Factory
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
            'title' => fake()->streetName().' Video',
            'style' => VideoStyle::Walkthrough,
            'aspect_ratio' => '16:9',
            'requested_duration' => 15,
            'resolution' => '720p',
            'provider' => 'fal',
            'model' => 'fal-ai/kling-video/v3/pro/image-to-video',
            'soundtrack_mode' => SoundtrackMode::None,
            'status' => VideoStatus::Draft,
        ];
    }

    public function inWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
        ]);
    }
}
