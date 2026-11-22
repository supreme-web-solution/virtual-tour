<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MediaAsset
 */
class MediaAssetResource extends JsonResource
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
            'asset_type' => $this->asset_type->value,
            'asset_type_label' => $this->asset_type->label(),
            'source_type' => $this->source_type->value,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'format' => $this->format,
            'file_size' => $this->file_size,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration === null ? null : (float) $this->duration,
            'resource_type' => $this->cloudinary_resource_type,
            'secure_url' => $this->secure_url,
            'thumbnail_url' => $this->thumbnail_url,
            'panorama' => $this->metadata['panorama'] ?? null,
            'property' => $this->whenLoaded('property', fn () => $this->property === null ? null : [
                'id' => $this->property->ulid,
                'name' => $this->property->name,
            ]),
            'uploader' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'enhancement_id' => $this->runningEnhancements($request)[$this->ulid] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
        ];
    }

    /**
     * Photo edits still running in this workspace, by source photo, loaded once per request.
     *
     * @return array<string, string>
     */
    protected function runningEnhancements(Request $request): array
    {
        return $request->attributes->get('running_enhancements') ?? tap(
            AiGeneration::query()
                ->where('workspace_id', $this->workspace_id)
                ->where('generation_type', GenerationType::PhotoEnhance)
                ->whereIn('status', array_values(array_filter(GenerationStatus::cases(), fn (GenerationStatus $status): bool => ! $status->isFinal())))
                ->get()
                ->mapWithKeys(fn (AiGeneration $generation): array => [(string) $generation->input('source_asset_id') => $generation->ulid])
                ->all(),
            fn (array $running) => $request->attributes->set('running_enhancements', $running),
        );
    }
}
