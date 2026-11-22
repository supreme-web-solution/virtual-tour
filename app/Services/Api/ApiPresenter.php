<?php

namespace App\Services\Api;

use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\TourLead;
use App\Models\TourScene;
use App\Models\VirtualTour;
use App\Services\Domains\PublicUrl;

/**
 * The public shape of workspace data in the REST API and MCP tools. Only
 * fields that are safe to hand to a customer's own integrations.
 */
class ApiPresenter
{
    public function __construct(protected PublicUrl $urls) {}

    /**
     * @return array<string, mixed>
     */
    public function property(Property $property): array
    {
        return [
            'id' => $property->ulid,
            'name' => $property->name,
            'type' => $property->property_type->value,
            'status' => $property->status->value,
            'description' => $property->description,
            'address' => $property->address,
            'city' => $property->city,
            'region' => $property->region,
            'country' => $property->country,
            'latitude' => $property->latitude,
            'longitude' => $property->longitude,
            'bedrooms' => $property->bedrooms,
            'bathrooms' => $property->bathrooms,
            'area' => $property->area,
            'area_unit' => $property->area_unit->value,
            'price' => $property->price,
            'currency' => $property->currency,
            'created_at' => $property->created_at?->toIso8601String(),
            'updated_at' => $property->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tour(VirtualTour $tour, bool $withScenes = false): array
    {
        return [
            'id' => $tour->ulid,
            'title' => $tour->title,
            'description' => $tour->description,
            'status' => $tour->status->value,
            'visibility' => $tour->visibility->value,
            'published_at' => $tour->published_at?->toIso8601String(),
            'url' => $tour->isPublished() ? url($this->urls->tour($tour)) : null,
            'property_id' => $tour->property?->ulid,
            'scene_count' => $tour->scenes_count ?? $tour->scenes->count(),
            'created_at' => $tour->created_at?->toIso8601String(),
            ...($withScenes ? ['scenes' => $tour->scenes->map(fn (TourScene $scene): array => [
                'id' => $scene->ulid,
                'title' => $scene->title,
                'description' => $scene->description,
                'visible' => $scene->is_visible,
                'position' => $scene->sort_order,
            ])->all()] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lead(TourLead $lead): array
    {
        return [
            'id' => $lead->ulid,
            'name' => $lead->name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'message' => $lead->message,
            'tour_id' => $lead->tour?->ulid,
            'read' => $lead->read_at !== null,
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function media(MediaAsset $asset): array
    {
        return [
            'id' => $asset->ulid,
            'type' => $asset->asset_type->value,
            'filename' => $asset->original_filename,
            'format' => $asset->format,
            'width' => $asset->width,
            'height' => $asset->height,
            'bytes' => $asset->file_size,
            'url' => $asset->secure_url,
            'thumbnail_url' => $asset->thumbnail_url,
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }
}
