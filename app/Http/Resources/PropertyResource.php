<?php

namespace App\Http\Resources;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Property
 */
class PropertyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'slug' => $this->slug,
            'property_type' => $this->property_type->value,
            'property_type_label' => $this->property_type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'description' => $this->description,
            'address' => $this->address,
            'city' => $this->city,
            'region' => $this->region,
            'country' => $this->country,
            'location' => $this->locationLabel(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'area' => $this->area,
            'area_unit' => $this->area_unit->value,
            'price' => $this->price,
            'currency' => $this->currency,
            'cover' => $this->whenLoaded('coverAsset', fn () => $this->coverAsset === null ? null : [
                'id' => $this->coverAsset->ulid,
                'thumbnail_url' => $this->coverAsset->thumbnail_url,
            ]),
            'media_count' => $this->whenCounted('mediaAssets'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
