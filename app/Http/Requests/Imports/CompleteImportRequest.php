<?php

namespace App\Http\Requests\Imports;

use App\Enums\AreaUnit;
use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\PropertyImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The reviewed listing details, the photos to copy, and the user's
 * confirmation that they may use them.
 */
class CompleteImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Property::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var PropertyImport $import */
        $import = $this->route('import');
        $creatingProperty = $this->input('target', 'new') === 'new';
        $required = $creatingProperty ? 'required' : 'nullable';

        return [
            'target' => ['required', Rule::in(['new', 'existing'])],
            'property_id' => [
                Rule::requiredIf(! $creatingProperty), 'nullable', 'string',
                Rule::exists(Property::class, 'ulid')->where('workspace_id', $import->workspace_id)->whereNull('deleted_at'),
            ],
            'details' => [$required, 'array'],
            'details.name' => [$required, 'string', 'max:160'],
            'details.property_type' => [$required, Rule::enum(PropertyType::class)],
            'details.description' => ['nullable', 'string', 'max:10000'],
            'details.address' => ['nullable', 'string', 'max:255'],
            'details.city' => ['nullable', 'string', 'max:120'],
            'details.region' => ['nullable', 'string', 'max:120'],
            'details.country' => ['nullable', 'string', 'max:120'],
            'details.latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:details.longitude'],
            'details.longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:details.latitude'],
            'details.bedrooms' => ['nullable', 'integer', 'min:0', 'max:500'],
            'details.bathrooms' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'details.area' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'details.area_unit' => [$required, Rule::enum(AreaUnit::class)],
            'details.price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'details.currency' => ['nullable', 'required_with:details.price', 'string', 'size:3', 'alpha'],
            'image_urls' => ['present', 'array', 'max:'.config('import.max_images', 30)],
            'image_urls.*' => ['string', 'distinct', Rule::in(array_column($import->images ?? [], 'url'))],
            'rights_confirmed' => [Rule::requiredIf(fn (): bool => $this->input('image_urls', []) !== []), 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rights_confirmed.required' => __('Confirm you have permission to use these photos.'),
            'rights_confirmed.accepted' => __('Confirm you have permission to use these photos.'),
            'image_urls.max' => __('Import at most :max photos at a time.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $details = (array) $this->validated('details', []);

        if (isset($details['currency'])) {
            $details['currency'] = strtoupper((string) $details['currency']);
        }

        return $details;
    }
}
