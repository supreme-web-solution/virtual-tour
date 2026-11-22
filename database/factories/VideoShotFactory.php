<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\VideoProject;
use App\Models\VideoShot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoShot>
 */
class VideoShotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_project_id' => VideoProject::factory(),
            'source_asset_id' => fn (array $attributes) => MediaAsset::factory()->create([
                'workspace_id' => VideoProject::whereKey($attributes['video_project_id'])->value('workspace_id'),
            ])->id,
            'sort_order' => 1,
            'duration' => 5,
            'camera_movement' => 'slow push-in',
            'scene_name' => 'Living Room',
            'prompt' => 'A bright living room with marble floors, gentle push-in.',
            'transition' => 'crossfade',
            'status' => 'pending',
        ];
    }

    public function forProject(VideoProject $project): static
    {
        return $this->state(fn (): array => ['video_project_id' => $project->id]);
    }
}
