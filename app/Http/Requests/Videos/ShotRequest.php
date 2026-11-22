<?php

namespace App\Http\Requests\Videos;

use App\Contracts\Video\VideoGenerationProvider;
use App\Enums\AssetType;
use App\Models\MediaAsset;
use App\Models\VideoProject;
use App\Models\VideoShot;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShotRequest extends FormRequest
{
    /**
     * Fields whose change makes the existing clip out of date.
     */
    public const CONTENT_FIELDS = ['source_asset_id', 'duration', 'camera_movement', 'prompt'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var VideoProject $project */
        $project = $this->route('video');
        $limits = app(VideoGenerationProvider::class)->capabilities();

        return [
            'source_asset_id' => ['sometimes', 'string', Rule::exists(MediaAsset::class, 'ulid')
                ->where('workspace_id', $project->workspace_id)
                ->whereIn('asset_type', [AssetType::Photo->value, AssetType::Panorama->value])],
            'duration' => ['sometimes', 'integer', 'between:'.$limits['min_seconds'].','.$limits['max_seconds']],
            'camera_movement' => ['sometimes', Rule::in(VideoShot::CAMERA_MOVEMENTS)],
            'scene_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'prompt' => ['sometimes', 'string', 'min:10', 'max:1000'],
            'transition' => ['sometimes', Rule::in(VideoShot::TRANSITIONS)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function shotAttributes(): array
    {
        $data = $this->validated();

        if (isset($data['source_asset_id'])) {
            $data['source_asset_id'] = MediaAsset::where('ulid', $data['source_asset_id'])->value('id');
        }

        return $data;
    }
}
