<?php

namespace App\Services\Api;

use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\TourLead;
use App\Models\VirtualTour;
use App\Models\Workspace;
use App\Services\Credits\CreditService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read queries for one workspace, shared by the REST API and the MCP tools
 * so both always show the same data.
 */
class WorkspaceData
{
    public function __construct(protected CreditService $credits) {}

    /**
     * @return array<string, mixed>
     */
    public function workspace(Workspace $workspace): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'credits_available' => $this->credits->available($workspace->id),
        ];
    }

    /**
     * @return Builder<Property>
     */
    public function properties(int $workspaceId, ?string $search = null): Builder
    {
        return Property::query()->where('workspace_id', $workspaceId)->search($search)->latest('id');
    }

    /**
     * @return Builder<VirtualTour>
     */
    public function tours(int $workspaceId): Builder
    {
        return VirtualTour::query()->where('workspace_id', $workspaceId)->with('property:id,ulid')->withCount('scenes')->latest('id');
    }

    /**
     * @return Builder<TourLead>
     */
    public function leads(int $workspaceId): Builder
    {
        return TourLead::query()->where('workspace_id', $workspaceId)->with('tour:id,ulid')->latest('id');
    }

    /**
     * @return Builder<MediaAsset>
     */
    public function media(int $workspaceId, ?string $type = null): Builder
    {
        return MediaAsset::query()
            ->where('workspace_id', $workspaceId)
            ->when($type, fn (Builder $query, string $type) => $query->where('asset_type', $type))
            ->latest('id');
    }

    public function tour(int $workspaceId, string $ulid): ?VirtualTour
    {
        return $this->tours($workspaceId)->where('ulid', $ulid)->with('scenes')->first();
    }

    public function property(int $workspaceId, string $ulid): ?Property
    {
        return Property::query()->where('workspace_id', $workspaceId)->where('ulid', $ulid)->first();
    }
}
