<?php

namespace App\Http\Requests\Properties;

use App\Enums\AreaUnit;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\MediaAsset;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for creating and updating a property. Authorization is
 * handled by PropertyPolicy in the controller.
 */
class PropertyRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency'))) ?: null]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'status' => ['required', Rule::enum(PropertyStatus::class)],
            'description' => ['nullable', 'string', 'max:10000'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:500'],
            'bathrooms' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'area' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'area_unit' => ['required', Rule::enum(AreaUnit::class)],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'currency' => ['nullable', 'required_with:price', 'string', 'size:3', 'alpha'],
            'cover_asset_id' => [
                'nullable',
                'string',
                Rule::exists(MediaAsset::class, 'ulid')
                    ->where('workspace_id', $this->workspaceId())
                    ->where('cloudinary_resource_type', 'image'),
            ],
        ];
    }

    /**
     * Validated attributes ready for the model, with the cover ULID resolved to its id.
     *
     * @return array<string, mixed>
     */
    public function propertyAttributes(): array
    {
        $data = $this->validated();

        $data['cover_asset_id'] = filled($data['cover_asset_id'] ?? null)
            ? MediaAsset::where('ulid', $data['cover_asset_id'])->value('id')
            : null;

        return $data;
    }

    /**
     * The workspace the property belongs (or will belong) to.
     */
    protected function workspaceId(): ?int
    {
        $property = $this->route('property');

        return $property instanceof Property
            ? $property->workspace_id
            : $this->user()?->current_workspace_id;
    }
}
