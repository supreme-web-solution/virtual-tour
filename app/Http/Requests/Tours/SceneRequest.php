<?php

namespace App\Http\Requests\Tours;

use App\Enums\AssetType;
use App\Models\MediaAsset;
use App\Models\TourScene;
use App\Models\VirtualTour;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial updates to a scene (title, initial view, visibility, panorama).
 * Angles are degrees.
 */
class SceneRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var VirtualTour $tour */
        $tour = $this->route('tour');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'initial_yaw' => ['sometimes', 'numeric', 'between:-180,180'],
            'initial_pitch' => ['sometimes', 'numeric', 'between:-90,90'],
            'initial_zoom' => ['sometimes', 'integer', 'between:0,100'],
            'is_visible' => ['sometimes', 'boolean'],
            // Map pin; send both, or both null to remove it.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'audio_settings' => ['sometimes', 'array:asset_id,volume,loop,pause_background'],
            'audio_settings.asset_id' => [
                'nullable',
                'string',
                Rule::exists(MediaAsset::class, 'ulid')
                    ->where('workspace_id', $tour->workspace_id)
                    ->where('asset_type', AssetType::Audio->value),
            ],
            'audio_settings.volume' => ['integer', 'between:0,100'],
            'audio_settings.loop' => ['boolean'],
            'audio_settings.pause_background' => ['boolean'],
            'media_asset_id' => [
                'sometimes',
                'string',
                Rule::exists(MediaAsset::class, 'ulid')
                    ->where('workspace_id', $tour->workspace_id)
                    ->where('asset_type', AssetType::Panorama->value),
            ],
        ];
    }

    /**
     * Validated attributes ready for the model.
     *
     * @return array<string, mixed>
     */
    public function sceneAttributes(): array
    {
        $data = $this->validated();

        if (isset($data['media_asset_id'])) {
            $data['media_asset_id'] = MediaAsset::where('ulid', $data['media_asset_id'])->value('id');
        }

        if (isset($data['audio_settings'])) {
            /** @var TourScene $scene */
            $scene = $this->route('scene');
            $data['audio_settings'] = array_merge($scene->audio_settings ?? [], $data['audio_settings']);
        }

        return $data;
    }
}
