<?php

namespace App\Http\Requests\Tours;

use App\Enums\TourVisibility;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\VirtualTour;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validates creating a tour (title, property) and updating its settings.
 * On update every field is optional so the editor can save one setting at a time.
 */
class TourRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tour = $this->route('tour');
        $updating = $tour instanceof VirtualTour;
        $workspaceId = $updating ? $tour->workspace_id : $this->user()?->current_workspace_id;
        $sometimes = $updating ? 'sometimes' : 'nullable';

        return [
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:160'],
            'description' => [$sometimes, 'nullable', 'string', 'max:5000'],
            'property_id' => [
                $sometimes,
                'nullable',
                'string',
                Rule::exists(Property::class, 'ulid')->where('workspace_id', $workspaceId)->whereNull('deleted_at'),
            ],
            'visibility' => [$sometimes, Rule::enum(TourVisibility::class)],
            'cover_asset_id' => [
                $sometimes,
                'nullable',
                'string',
                Rule::exists(MediaAsset::class, 'ulid')
                    ->where('workspace_id', $workspaceId)
                    ->where('cloudinary_resource_type', 'image'),
            ],
            'viewer_settings' => ['sometimes', 'array:start_scene_id,show_scene_gallery,show_prev_next,show_scene_label,auto_rotate,auto_rotate_speed,transition,layout'],
            'viewer_settings.start_scene_id' => ['nullable', 'string'],
            'viewer_settings.show_scene_gallery' => ['boolean'],
            'viewer_settings.show_prev_next' => ['boolean'],
            'viewer_settings.show_scene_label' => ['boolean'],
            'viewer_settings.auto_rotate' => ['boolean'],
            'viewer_settings.auto_rotate_speed' => [Rule::in(['slow', 'normal', 'fast'])],
            'viewer_settings.transition' => [Rule::in(['fade', 'black', 'none'])],
            'viewer_settings.layout' => [Rule::in(['classic', 'carousel', 'minimal', 'gallery', 'sidebar'])],
            'allowed_embed_domains' => ['sometimes', 'array', 'max:20'],
            'allowed_embed_domains.*' => ['string', 'max:253', 'regex:/^(\*\.)?([a-z0-9-]+\.)+[a-z]{2,}$/i'],
            'analytics_enabled' => ['sometimes', 'boolean'],

            'branding' => ['sometimes', 'array:logo_asset_id,primary_color,accent_color,show_platform_branding,intro,contact'],
            'branding.logo_asset_id' => ['nullable', 'string', $this->assetRule($workspaceId, ['logo', 'photo'])],
            'branding.primary_color' => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'],
            'branding.accent_color' => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'],
            'branding.show_platform_branding' => ['boolean'],
            'branding.intro' => ['array:enabled,title,text,button_label'],
            'branding.intro.enabled' => ['boolean'],
            'branding.intro.title' => ['nullable', 'string', 'max:120'],
            'branding.intro.text' => ['nullable', 'string', 'max:1000'],
            'branding.intro.button_label' => ['nullable', 'string', 'max:40'],
            'branding.contact' => ['array:enabled,name,email,phone,website'],
            'branding.contact.enabled' => ['boolean'],
            'branding.contact.name' => ['nullable', 'string', 'max:120'],
            'branding.contact.email' => ['nullable', 'email', 'max:255'],
            'branding.contact.phone' => ['nullable', 'string', 'max:40'],
            'branding.contact.website' => ['nullable', 'url:http,https', 'max:255'],

            'audio_settings' => ['sometimes', 'array:background_asset_id,volume,loop'],
            'audio_settings.background_asset_id' => ['nullable', 'string', $this->assetRule($workspaceId, ['audio'])],
            'audio_settings.volume' => ['integer', 'between:0,100'],
            'audio_settings.loop' => ['boolean'],

            'lead_settings' => ['sometimes', 'array:enabled,title,button_label,require_phone,thank_you,notify'],
            'lead_settings.enabled' => ['boolean'],
            'lead_settings.title' => ['nullable', 'string', 'max:120'],
            'lead_settings.button_label' => ['nullable', 'string', 'max:40'],
            'lead_settings.require_phone' => ['boolean'],
            'lead_settings.thank_you' => ['nullable', 'string', 'max:300'],
            'lead_settings.notify' => ['boolean'],

            'map_settings' => ['sometimes', 'array:enabled'],
            'map_settings.enabled' => ['boolean'],
        ];
    }

    /**
     * An asset ULID from the workspace with one of the given types.
     *
     * @param  list<string>  $types
     */
    protected function assetRule(?int $workspaceId, array $types): Exists
    {
        return Rule::exists(MediaAsset::class, 'ulid')
            ->where('workspace_id', $workspaceId)
            ->whereIn('asset_type', $types);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allowed_embed_domains.*.regex' => __('Enter a domain like example.com or *.example.com, without https://.'),
        ];
    }

    /**
     * Resolve a property ULID to its id.
     */
    public function propertyId(): ?int
    {
        $ulid = $this->validated('property_id');

        return $ulid ? Property::where('ulid', $ulid)->value('id') : null;
    }

    /**
     * Resolve a cover ULID to its id.
     */
    public function coverAssetId(): ?int
    {
        $ulid = $this->validated('cover_asset_id');

        return $ulid ? MediaAsset::where('ulid', $ulid)->value('id') : null;
    }
}
