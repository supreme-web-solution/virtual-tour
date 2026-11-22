<?php

namespace App\Services\Domains;

use App\Enums\TourStatus;
use App\Enums\TourVisibility;
use App\Models\VideoProject;
use App\Models\VirtualTour;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * The public channel: a searchable gallery of a workspace's public tours and
 * videos. Shared by the channel page (/c/{slug}) and a custom domain's home
 * page. Unlisted and private items never appear.
 */
class ChannelPresenter
{
    public const SORTS = ['newest', 'popular', 'name'];

    public function __construct(protected BrandPresenter $brands, protected PublicUrl $urls) {}

    /**
     * @return array<string, mixed>
     */
    public function page(Workspace $workspace, Request $request): array
    {
        $search = mb_substr(trim($request->string('q')->toString()), 0, 80);
        $sort = in_array($request->string('sort')->toString(), self::SORTS, true) ? $request->string('sort')->toString() : 'newest';
        $tab = $request->string('tab')->toString() === 'videos' ? 'videos' : 'tours';

        $publicTours = fn () => VirtualTour::where('workspace_id', $workspace->id)
            ->where('status', TourStatus::Published)
            ->where('visibility', TourVisibility::Public);
        $publicVideos = fn () => VideoProject::where('workspace_id', $workspace->id)
            ->whereNotNull('published_at')
            ->whereNotNull('final_asset_id')
            ->where('visibility', TourVisibility::Public);

        $like = '%'.addcslashes($search, '%_\\').'%';

        $tours = $publicTours()
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('title', 'like', $like)->orWhere('description', 'like', $like)))
            ->with(['coverAsset', 'property:id,name,city,country'])
            ->withCount('scenes')
            ->withCount('visits')
            ->when($sort === 'popular', fn ($query) => $query->orderByDesc('visits_count')->latest('published_at'))
            ->when($sort === 'name', fn ($query) => $query->orderBy('title'))
            ->when($sort === 'newest', fn ($query) => $query->latest('published_at'))
            ->limit(48)
            ->get()
            ->map(fn (VirtualTour $tour): array => [
                'id' => $tour->public_id,
                'title' => $tour->title,
                'description' => $tour->description,
                'location' => $tour->property?->locationLabel(),
                'cover_url' => $tour->coverAsset->thumbnail_url ?? null,
                'scenes_count' => (int) $tour->getAttribute('scenes_count'),
                'views' => (int) $tour->getAttribute('visits_count'),
                'url' => $this->urls->tour($tour),
            ])->values();

        $videos = $publicVideos()
            ->when($search !== '', fn ($query) => $query->where('title', 'like', $like))
            ->with('posterAsset')
            ->when($sort === 'popular', fn ($query) => $query->orderByDesc('views_count')->latest('published_at'))
            ->when($sort === 'name', fn ($query) => $query->orderBy('title'))
            ->when($sort === 'newest', fn ($query) => $query->latest('published_at'))
            ->limit(48)
            ->get()
            ->map(fn (VideoProject $video): array => [
                'id' => $video->public_id,
                'title' => $video->title,
                'poster_url' => $video->posterAsset->secure_url ?? null,
                'views' => $video->views_count,
                'url' => $this->urls->video($video),
            ])->values();

        $brand = $this->brands->forWorkspace($workspace);

        return [
            'brand' => $brand,
            'tours' => $tours,
            'videos' => $videos,
            'totals' => ['tours' => $publicTours()->count(), 'videos' => $publicVideos()->count()],
            'filters' => ['q' => $search, 'sort' => $sort, 'tab' => $tab],
            'siteName' => $brand['site_name'],
        ];
    }
}
