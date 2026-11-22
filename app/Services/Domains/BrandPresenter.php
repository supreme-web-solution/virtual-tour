<?php

namespace App\Services\Domains;

use App\Contracts\Media\MediaStorage;
use App\Enums\Entitlement;
use App\Models\MediaAsset;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;

/**
 * How a workspace presents itself on public pages. With white-label (the
 * plan's remove-branding feature) the workspace's name replaces the
 * platform's name in titles, previews and badges.
 */
class BrandPresenter
{
    /** @var array<int, array<string, mixed>> */
    protected array $cache = [];

    public function __construct(
        protected MediaStorage $storage,
        protected EntitlementService $entitlements,
    ) {}

    public function forget(?int $workspaceId = null): void
    {
        if ($workspaceId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$workspaceId]);
        }
    }

    /**
     * @return array{name: string, logo_url: string|null, primary_color: string|null, website: string|null, white_label: bool, site_name: string}
     */
    public function forWorkspace(Workspace $workspace): array
    {
        if (! isset($this->cache[$workspace->id])) {
            $brand = $workspace->brand();
            $whiteLabel = $this->entitlements->allows($workspace->id, Entitlement::RemoveBranding);
            $logo = $brand['logo_asset_id']
                ? MediaAsset::where('workspace_id', $workspace->id)->where('ulid', $brand['logo_asset_id'])->first()
                : null;

            $this->cache[$workspace->id] = [
                'name' => $workspace->brandName(),
                'logo_url' => $logo ? $this->storage->scaledImageUrl($logo->cloudinary_public_id, 400) : null,
                'primary_color' => $brand['primary_color'],
                'website' => $brand['website'],
                'white_label' => $whiteLabel,
                // Shown after page titles and as og:site_name.
                'site_name' => $whiteLabel ? $workspace->brandName() : (string) config('app.name'),
            ];
        }

        /** @var array{name: string, logo_url: string|null, primary_color: string|null, website: string|null, white_label: bool, site_name: string} */
        return $this->cache[$workspace->id];
    }
}
