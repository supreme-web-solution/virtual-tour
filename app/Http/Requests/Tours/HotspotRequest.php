<?php

namespace App\Http\Requests\Tours;

use App\Enums\AssetType;
use App\Enums\HotspotType;
use App\Models\MediaAsset;
use App\Models\TourHotspot;
use App\Models\TourScene;
use App\Models\VirtualTour;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates or updates a hotspot. Angles are degrees. On update, fields not
 * sent keep their current values (so dragging can send only yaw/pitch).
 */
class HotspotRequest extends FormRequest
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
        $hotspot = $this->route('hotspot');
        $updating = $hotspot instanceof TourHotspot;
        $type = $this->input('type', $updating ? $hotspot->type->value : null);
        $required = $updating ? 'sometimes' : 'required';

        return [
            'type' => [$required, Rule::enum(HotspotType::class)],
            'yaw' => [$required, 'numeric', 'between:-180,180'],
            'pitch' => [$required, 'numeric', 'between:-90,90'],
            'title' => [
                $type === HotspotType::Navigation->value ? 'nullable' : ($updating ? 'sometimes' : 'required'),
                'string',
                'max:120',
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'target_scene_id' => [
                Rule::requiredIf($type === HotspotType::Navigation->value && (! $updating || $this->has('type'))),
                'nullable',
                'string',
                Rule::exists(TourScene::class, 'ulid')->where('virtual_tour_id', $tour->id),
                Rule::notIn([$this->sourceScene()?->ulid]),
            ],
            'external_url' => [
                Rule::requiredIf($type === HotspotType::Link->value && (! $updating || $this->has('type'))),
                'nullable',
                'url:http,https',
                'max:2048',
            ],
            'media_asset_id' => [
                Rule::requiredIf($type === HotspotType::Media->value && (! $updating || $this->has('type'))),
                'nullable',
                'string',
                Rule::exists(MediaAsset::class, 'ulid')
                    ->where('workspace_id', $tour->workspace_id)
                    ->whereIn('asset_type', [AssetType::Photo->value, AssetType::Panorama->value, AssetType::Video->value]),
            ],
            'settings' => ['sometimes', 'array:open_in_new_tab,placement,size,color,opacity,rotation,label_mode,icon'],
            'settings.open_in_new_tab' => ['boolean'],
            // How the pin looks: flat on the floor, on a wall, or a round sphere pin.
            'settings.placement' => ['sometimes', Rule::in(['sphere', 'floor', 'wall'])],
            'settings.size' => ['sometimes', 'integer', 'between:20,80'],
            'settings.color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.opacity' => ['sometimes', 'numeric', 'between:0.3,1'],
            'settings.rotation' => ['sometimes', 'integer', 'between:0,359'],
            'settings.label_mode' => ['sometimes', Rule::in(['always', 'hover'])],
            // A glyph key from the pin icon set (null = the default for the pin's type).
            'settings.icon' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9-]{1,24}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_scene_id.required' => __('Choose the scene this hotspot leads to.'),
            'target_scene_id.not_in' => __('A hotspot cannot lead to the scene it is in.'),
            'external_url.required' => __('Enter the link address.'),
            'media_asset_id.required' => __('Choose the image or video to show.'),
        ];
    }

    /**
     * Validated attributes ready for the model, with only the fields that
     * apply to the hotspot's type kept.
     *
     * @return array<string, mixed>
     */
    public function hotspotAttributes(): array
    {
        $data = $this->validated();
        $hotspot = $this->route('hotspot');
        $type = isset($data['type'])
            ? HotspotType::from($data['type'])
            : ($hotspot instanceof TourHotspot ? $hotspot->type : HotspotType::Info);

        if (array_key_exists('target_scene_id', $data)) {
            $data['target_scene_id'] = $data['target_scene_id']
                ? TourScene::where('ulid', $data['target_scene_id'])->value('id')
                : null;
        }

        if ($type !== HotspotType::Navigation) {
            $data['target_scene_id'] = null;
        }

        if ($type !== HotspotType::Link) {
            $data['external_url'] = null;
        }

        if (array_key_exists('media_asset_id', $data)) {
            $data['media_asset_id'] = $data['media_asset_id']
                ? MediaAsset::where('ulid', $data['media_asset_id'])->value('id')
                : null;
        }

        if ($type !== HotspotType::Media) {
            $data['media_asset_id'] = null;
        }

        return $data;
    }

    protected function sourceScene(): ?TourScene
    {
        $scene = $this->route('scene');
        $hotspot = $this->route('hotspot');

        return $scene instanceof TourScene ? $scene : ($hotspot instanceof TourHotspot ? $hotspot->scene : null);
    }
}
