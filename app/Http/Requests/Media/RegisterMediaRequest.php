<?php

namespace App\Http\Requests\Media;

use App\Enums\AssetType;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterMediaRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'public_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-\/]+$/'],
            'resource_type' => ['required', Rule::in(['image', 'video'])],
            'asset_type' => ['required', Rule::enum(AssetType::class)],
            'property_id' => [
                'nullable',
                'string',
                Rule::exists(Property::class, 'ulid')
                    ->where('workspace_id', $this->user()?->current_workspace_id)
                    ->whereNull('deleted_at'),
            ],
            'original_filename' => ['nullable', 'string', 'max:255'],
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
