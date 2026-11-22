<?php

namespace App\Http\Controllers\Videos;

use App\Enums\TourVisibility;
use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleCustomDomain;
use App\Models\VideoProject;
use App\Services\Domains\BrandPresenter;
use App\Services\Domains\PublicUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public video page (/v/{publicId}), embeds, play counts and downloads.
 */
class PublicVideoController extends Controller
{
    public function show(Request $request, string $publicId, ?string $slug = null): Response
    {
        $video = $this->accessible($request, $publicId);
        $embed = $request->boolean('embed');
        $brand = app(BrandPresenter::class)->forWorkspace($video->workspace);

        $video->increment($embed ? 'embed_views_count' : 'views_count');

        $description = Str::limit((string) ($video->storyboard['creative_direction'] ?? $video->property->name ?? $video->title), 160);

        $response = Inertia::render('public/VideoPlayer', [
            'video' => [
                'id' => $video->public_id,
                'title' => $video->title,
                'url' => $video->finalAsset?->secure_url,
                'poster_url' => $video->posterAsset?->secure_url,
                'aspect_ratio' => $video->aspect_ratio,
                'property' => $video->property === null ? null : ['name' => $video->property->name, 'location' => $video->property->locationLabel()],
                // Relative, so they stay on whichever domain the page was opened on.
                'download_url' => route('videos.public.download', ['publicId' => $video->public_id], absolute: false),
                'events_url' => route('videos.public.events', ['publicId' => $video->public_id], absolute: false),
            ],
            'embed' => $embed,
            'brand' => $brand,
            'siteName' => $brand['site_name'],
        ])->withViewData([
            'seo' => [
                'title' => $video->title,
                'description' => $description,
                'image' => $video->posterAsset?->secure_url,
                'url' => app(PublicUrl::class)->video($video),
                'site_name' => $brand['site_name'],
                'indexable' => $video->visibility === TourVisibility::Public,
            ],
        ])->toResponse($request);

        $response->headers->set('Content-Security-Policy', 'frame-ancestors *');

        return $response;
    }

    /**
     * Count a play or a completed play (sent from the player).
     */
    public function event(Request $request, string $publicId): HttpResponse
    {
        $video = $this->accessible($request, $publicId);
        $type = $request->input('type');

        match ($type) {
            'play' => $video->increment('plays_count'),
            'complete' => $video->increment('completions_count'),
            default => null,
        };

        return response()->noContent();
    }

    public function download(Request $request, string $publicId): RedirectResponse
    {
        $video = $this->accessible($request, $publicId);
        $video->increment('downloads_count');

        $url = (string) $video->finalAsset?->secure_url;

        // Ask the CDN to serve the file as a download.
        return redirect()->away(str_contains($url, '/video/upload/')
            ? str_replace('/video/upload/', '/video/upload/fl_attachment/', $url)
            : $url);
    }

    protected function accessible(Request $request, string $publicId): VideoProject
    {
        $video = VideoProject::where('public_id', $publicId)->with(['finalAsset', 'posterAsset', 'property', 'workspace'])->firstOrFail();

        // On a custom domain, only that workspace's content exists.
        abort_if(($domain = HandleCustomDomain::current($request)) !== null && $domain->workspace_id !== $video->workspace_id, 404);

        abort_unless($video->isPublished(), 404);

        if ($video->visibility === TourVisibility::Private) {
            abort_unless($request->user()?->can('view', $video), 404);
        }

        return $video;
    }
}
