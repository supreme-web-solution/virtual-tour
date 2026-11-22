<?php

namespace App\Http\Requests\Videos;

use App\Enums\AssetType;
use App\Enums\SoundtrackMode;
use App\Enums\VideoStyle;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\VideoProject;
use App\Models\VirtualTour;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Creating a video project (the wizard) or updating its settings and sound.
 */
class VideoRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('video');
        $updating = $project instanceof VideoProject;
        $workspaceId = $updating ? $project->workspace_id : $this->user()?->current_workspace_id;
        $required = $updating ? 'sometimes' : 'required';
        $mode = $this->input('soundtrack_mode', $updating ? $project->soundtrack_mode->value : null);

        return [
            'title' => [$required, 'string', 'max:160'],
            'property_id' => ['nullable', 'string', Rule::exists(Property::class, 'ulid')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'virtual_tour_id' => ['nullable', 'string', Rule::exists(VirtualTour::class, 'ulid')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'style' => [$updating ? 'prohibited' : 'required', Rule::enum(VideoStyle::class)],
            'aspect_ratio' => [$updating ? 'prohibited' : 'required', Rule::in(VideoProject::ASPECT_RATIOS)],
            'duration' => [$updating ? 'prohibited' : 'required', 'integer', Rule::in(VideoProject::DURATIONS)],
            'source_ids' => [$updating ? 'prohibited' : 'required', 'array', 'min:1', 'max:'.config('ai.video.max_sources', 9)],
            'source_ids.*' => ['string', 'distinct', $this->asset($workspaceId, [AssetType::Photo, AssetType::Panorama])],
            'soundtrack_mode' => [$required, Rule::enum(SoundtrackMode::class)],
            'soundtrack_asset_id' => [
                Rule::requiredIf($mode === SoundtrackMode::OwnAudio->value),
                'nullable', 'string', $this->asset($workspaceId, [AssetType::Audio]),
            ],
            'voiceover_asset_id' => ['nullable', 'string', $this->asset($workspaceId, [AssetType::Audio])],
            'settings' => ['sometimes', 'array:music_mood,ambience,music_volume,voiceover_volume,audio_trim_start,audio_fade_in,audio_fade_out,branded_outro,brief'],
            'settings.music_mood' => [Rule::in(SoundtrackMode::MOODS)],
            'settings.ambience' => [Rule::in(SoundtrackMode::AMBIENCES)],
            'settings.music_volume' => ['integer', 'between:0,100'],
            'settings.voiceover_volume' => ['integer', 'between:0,150'],
            'settings.audio_trim_start' => ['numeric', 'between:0,600'],
            'settings.audio_fade_in' => ['numeric', 'between:0,10'],
            'settings.audio_fade_out' => ['numeric', 'between:0,10'],
            'settings.branded_outro' => ['boolean'],
            'settings.brief' => [
                Rule::requiredIf(! $updating && $this->input('style') === VideoStyle::Custom->value),
                'nullable', 'string', 'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'settings.brief.required' => __('Describe the video you want for a custom style.'),
            'soundtrack_asset_id.required' => __('Choose an audio file from your media library.'),
        ];
    }

    /**
     * Resolve ULIDs to ids for the model.
     *
     * @return array<string, mixed>
     */
    public function projectAttributes(): array
    {
        $data = $this->validated();

        foreach (['property_id' => Property::class, 'virtual_tour_id' => VirtualTour::class] as $key => $model) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] ? $model::where('ulid', $data[$key])->value('id') : null;
            }
        }

        foreach (['soundtrack_asset_id', 'voiceover_asset_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] ? MediaAsset::where('ulid', $data[$key])->value('id') : null;
            }
        }

        return $data;
    }

    /**
     * Source asset ids in the order the user chose them.
     *
     * @return list<int>
     */
    public function sourceIds(): array
    {
        $ulids = array_values((array) $this->validated('source_ids', []));
        $ids = MediaAsset::whereIn('ulid', $ulids)->pluck('id', 'ulid');

        return array_map(fn (string $ulid): int => (int) $ids[$ulid], $ulids);
    }

    /**
     * @param  list<AssetType>  $types
     */
    protected function asset(?int $workspaceId, array $types): Exists
    {
        return Rule::exists(MediaAsset::class, 'ulid')
            ->where('workspace_id', $workspaceId)
            ->whereIn('asset_type', array_map(fn (AssetType $type): string => $type->value, $types));
    }
}
