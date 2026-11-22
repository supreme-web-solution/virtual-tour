<?php

namespace App\Services\AI;

use App\Enums\GenerationType;
use App\Models\AiGeneration;
use App\Models\MediaAsset;
use App\Models\User;

class GenerationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function summary(AiGeneration $generation, ?User $viewer = null): array
    {
        $type = $generation->generation_type;
        $assetId = $generation->output_metadata['media_asset_id'] ?? null;
        $asset = $assetId ? MediaAsset::where('ulid', $assetId)->first(['ulid', 'thumbnail_url']) : null;
        $canUpdate = $viewer?->can('update', $generation) ?? false;

        return [
            'id' => $generation->ulid,
            'type' => $type->value,
            'type_label' => $type->label(),
            'status' => $generation->status->value,
            'is_active' => $generation->status->isActive(),
            'title' => $this->title($generation),
            'error' => $generation->error_message,
            'credits' => $generation->credits_charged ?: $generation->credits_reserved,
            'thumbnail_url' => $asset?->thumbnail_url,
            'created_at' => $generation->created_at?->toIso8601String(),
            'links' => [
                'plan' => $type === GenerationType::ScenePlan ? route('ai-studio.plans.show', $generation) : null,
                'analysis' => $type === GenerationType::PhotoAnalysis ? route('ai-studio.analyses.show', $generation) : null,
                'tour' => $generation->tour ? route('tours.edit', $generation->tour) : null,
            ],
            'can' => [
                'regenerate' => $canUpdate && $type->producesPanorama() && $generation->status->isFinal(),
                'cancel' => $canUpdate && $type->producesPanorama() && $generation->status->isActive(),
            ],
        ];
    }

    protected function title(AiGeneration $generation): string
    {
        return match ($generation->generation_type) {
            GenerationType::ScenePlan => (string) ($generation->output_metadata['plan']['tour_title'] ?? str((string) $generation->input('description'))->limit(60)),
            GenerationType::PhotoAnalysis => count((array) $generation->input('photo_ids', [])).' photos',
            default => (string) $generation->input('title', $generation->generation_type->label()),
        };
    }
}
