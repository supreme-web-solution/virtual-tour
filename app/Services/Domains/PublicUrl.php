<?php

namespace App\Services\Domains;

use App\Enums\Entitlement;
use App\Models\VideoProject;
use App\Models\VirtualTour;
use App\Models\WorkspaceDomain;
use App\Services\Billing\EntitlementService;

/**
 * Share links for public tours and videos: on the workspace's verified
 * custom domain when it has one (and its plan includes it), otherwise on
 * the platform domain.
 */
class PublicUrl
{
    /** @var array<int, string|null> */
    protected array $bases = [];

    public function __construct(protected EntitlementService $entitlements) {}

    public function tour(VirtualTour $tour): string
    {
        return $this->build($tour->workspace_id, route('tours.public', ['publicId' => $tour->public_id, 'slug' => $tour->slug], absolute: false));
    }

    public function video(VideoProject $video): string
    {
        return $this->build($video->workspace_id, route('videos.public', ['publicId' => $video->public_id, 'slug' => $video->slug], absolute: false));
    }

    /**
     * The custom domain origin ("https://tours.agency.com"), or null.
     */
    public function customOrigin(int $workspaceId): ?string
    {
        if (! array_key_exists($workspaceId, $this->bases)) {
            $domain = WorkspaceDomain::where('workspace_id', $workspaceId)->where('status', 'verified')->first();
            $this->bases[$workspaceId] = $domain !== null && $this->entitlements->allows($workspaceId, Entitlement::CustomDomain)
                ? $domain->url()
                : null;
        }

        return $this->bases[$workspaceId];
    }

    public function forget(?int $workspaceId = null): void
    {
        if ($workspaceId === null) {
            $this->bases = [];
        } else {
            unset($this->bases[$workspaceId]);
        }
    }

    protected function build(int $workspaceId, string $path): string
    {
        return ($this->customOrigin($workspaceId) ?? rtrim((string) config('app.url'), '/')).$path;
    }
}
