<?php

namespace App\Http\Requests\Admin;

use App\Enums\Entitlement;
use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('admin');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Plan|null $plan */
        $plan = $this->route('plan');

        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'string', 'max:60', 'alpha_dash:ascii', Rule::unique(Plan::class, 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'price_monthly' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'between:0,1000'],
            'entitlements' => ['required', 'array'],
        ];

        foreach (Entitlement::cases() as $entitlement) {
            $rules["entitlements.{$entitlement->value}"] = $entitlement->isFeature()
                ? ['required', 'boolean']
                : ['present', 'nullable', 'integer', 'min:0', 'max:10000000'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function planAttributes(): array
    {
        $entitlements = [];

        foreach (Entitlement::cases() as $entitlement) {
            $value = $this->input("entitlements.{$entitlement->value}");
            $entitlements[$entitlement->value] = $entitlement->isFeature()
                ? (bool) $value
                : ($value === null || $value === '' ? null : (int) $value);
        }

        return [
            'name' => $this->string('name')->trim()->toString(),
            'slug' => strtolower($this->string('slug')->toString()),
            'description' => $this->input('description'),
            'price_monthly' => $this->filled('price_monthly') ? $this->integer('price_monthly') : null,
            'currency' => strtoupper($this->string('currency')->toString()),
            'is_default' => $this->boolean('is_default'),
            'is_active' => $this->boolean('is_active', true),
            'sort_order' => $this->integer('sort_order'),
            'entitlements' => $entitlements,
        ];
    }
}
