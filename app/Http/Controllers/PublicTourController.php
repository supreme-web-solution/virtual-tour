<?php

namespace App\Http\Controllers;

use App\Enums\Entitlement;
use App\Enums\TourVisibility;
use App\Http\Middleware\HandleCustomDomain;
use App\Models\VirtualTour;
use App\Services\Billing\EntitlementService;
use App\Services\Domains\BrandPresenter;
use App\Services\Domains\PublicUrl;
use App\Services\Tours\TourPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class PublicTourController extends Controller
{
    public function __construct(
        protected TourPresenter $presenter,
        protected BrandPresenter $brands,
        protected PublicUrl $urls,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * The public tour viewer at /t/{publicId}/{slug?}. Unpublished and
     * private tours respond 404 to anyone not allowed to see them, so their
     * existence is not revealed.
     */
    public function show(Request $request, string $publicId, ?string $slug = null): Response
    {
        $tour = VirtualTour::where('public_id', $publicId)->firstOrFail();

        // On a custom domain, only that workspace's content exists.
        abort_if(($domain = HandleCustomDomain::current($request)) !== null && $domain->workspace_id !== $tour->workspace_id, 404);

        abort_unless($tour->isPublished(), 404);

        if ($tour->visibility === TourVisibility::Private) {
            abort_unless($request->user()?->can('view', $tour), 404);
        }

        return $this->render($request, $tour, preview: false);
    }

    /**
     * Owner preview of the tour exactly as visitors will see it, published or not.
     */
    public function preview(Request $request, VirtualTour $tour): Response
    {
        Gate::authorize('view', $tour);

        return $this->render($request, $tour, preview: true);
    }

    protected function render(Request $request, VirtualTour $tour, bool $preview): Response
    {
        $payload = $this->presenter->forPublic($tour);
        $embed = $request->boolean('embed');
        $brand = $this->brands->forWorkspace($tour->workspace);

        // MLS-ready link (?mls=1): the same tour with no branding, contact details or enquiry form.
        $unbranded = $request->boolean('mls') && $this->entitlements->allows($tour->workspace_id, Entitlement::RemoveBranding);

        if ($unbranded) {
            $payload = $this->withoutBranding($payload);
        }

        $response = Inertia::render('public/TourViewer', [
            'tour' => $payload,
            'embed' => $embed,
            'preview' => $preview,
            'siteName' => $unbranded ? $tour->title : $brand['site_name'],
            'unbranded' => $unbranded,
            'tracking' => $preview || $unbranded ? null : $this->trackingIds($tour),
        ])->withViewData([
            'seo' => [
                'title' => $tour->title,
                'description' => Str::limit((string) ($tour->description ?: $payload['property']['name'] ?? $tour->title), 160),
                'image' => $payload['cover_url'],
                'url' => $this->urls->tour($tour),
                'site_name' => $unbranded ? $tour->title : $brand['site_name'],
                'indexable' => ! $preview && ! $unbranded && $tour->visibility === TourVisibility::Public,
            ],
        ])->toResponse($request);

        $response->headers->set('Content-Security-Policy', $this->frameAncestors($tour));

        return $response;
    }

    /**
     * The workspace's Google Analytics / Tag Manager IDs, when its plan includes them.
     *
     * @return array{ga4: string|null, gtm: string|null}|null
     */
    protected function trackingIds(VirtualTour $tour): ?array
    {
        if (! $this->entitlements->allows($tour->workspace_id, Entitlement::TrackingScripts)) {
            return null;
        }

        $brand = $tour->workspace->brand();

        return $brand['ga4_id'] || $brand['gtm_id'] ? ['ga4' => $brand['ga4_id'], 'gtm' => $brand['gtm_id']] : null;
    }

    /**
     * Take out everything MLS rules usually forbid: logos, intro, contact
     * details, the enquiry form and the platform badge.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withoutBranding(array $payload): array
    {
        $payload['branding'] = [
            ...$payload['branding'],
            'logo_url' => null,
            'contact' => null,
            'show_platform_branding' => false,
            'intro' => [...$payload['branding']['intro'], 'enabled' => false],
        ];
        $payload['lead'] = null;

        return $payload;
    }

    /**
     * Restrict which sites may embed the tour when the owner configured domains.
     */
    protected function frameAncestors(VirtualTour $tour): string
    {
        $domains = $tour->allowedEmbedDomains();

        if ($domains === []) {
            return 'frame-ancestors *';
        }

        $sources = collect($domains)
            ->flatMap(fn (string $domain): array => ["https://{$domain}", "http://{$domain}"])
            ->prepend("'self'")
            ->implode(' ');

        return "frame-ancestors {$sources}";
    }
}
