<?php

namespace App\Http\Requests\Media;

use App\Enums\AssetType;
use App\Models\MediaAsset;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMediaRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var MediaAsset $asset */
        $asset = $this->route('media');

        // Images may only be reclassified as other image types; audio and video keep their type.
        $compatibleTypes = $asset->cloudinary_resource_type === 'image'
            ? array_filter(AssetType::cases(), fn (AssetType $type): bool => $type->resourceType() === 'image')
            : [$asset->asset_type];

        return [
            'property_id' => [
                'nullable',
                'string',
                Rule::exists(Property::class, 'ulid')
                    ->where('workspace_id', $asset->workspace_id)
                    ->whereNull('deleted_at'),
            ],
            'original_filename' => ['nullable', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(array_map(fn (AssetType $type): string => $type->value, $compatibleTypes))],
        ];
    }

    /**
     * The internal id of the selected property, if any.
     */
    public function propertyId(): ?int
    {
        $ulid = $this->validated('property_id');

        return $ulid ? Property::where('ulid', $ulid)->value('id') : null;
    }
}
