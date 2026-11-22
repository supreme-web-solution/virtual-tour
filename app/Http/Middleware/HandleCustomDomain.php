<?php

namespace App\Http\Middleware;

use App\Http\Controllers\DomainShowcaseController;
use App\Models\WorkspaceDomain;
use App\Services\Domains\DomainService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests on a workspace's custom domain may only reach that workspace's
 * public tours and videos (and its home page). Logins, the app and every
 * other route stay on the platform domain; unknown hosts get a 404.
 */
class HandleCustomDomain
{
    /**
     * Route names a custom domain may serve.
     */
    public const PUBLIC_ROUTES = [
        'tours.public', 'tours.public.visits', 'tours.public.events', 'tours.public.leads',
        'videos.public', 'videos.public.events', 'videos.public.download',
    ];

    public function __construct(protected DomainService $domains) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        if ($this->isAppHost($host) || $request->routeIs('domains.tls-check')) {
            return $next($request);
        }

        $domain = $this->domains->forHost($host);
        abort_if($domain === null, 404);

        $isPublic = $request->routeIs(...self::PUBLIC_ROUTES);

        if (! $this->domains->serves($domain)) {
            // The plan no longer includes custom domains: send visitors to the platform URL.
            return $isPublic && $request->isMethod('GET')
                ? redirect()->away(rtrim((string) config('app.url'), '/').$request->getRequestUri())
                : abort(404);
        }

        $request->attributes->set('custom_domain', $domain);

        if ($request->routeIs('home')) {
            return app(DomainShowcaseController::class)($request, $domain)->toResponse($request);
        }

        abort_unless($isPublic, 404);

        return $next($request);
    }

    /**
     * The workspace whose custom domain the request came in on, if any.
     */
    public static function current(Request $request): ?WorkspaceDomain
    {
        $domain = $request->attributes->get('custom_domain');

        return $domain instanceof WorkspaceDomain ? $domain : null;
    }

    protected function isAppHost(string $host): bool
    {
        foreach (DomainService::appHosts() as $appHost) {
            if ($host === $appHost || $host === 'www.'.Str::after($appHost, 'www.')) {
                return true;
            }
        }

        return false;
    }
}
