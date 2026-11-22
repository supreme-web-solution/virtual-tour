<?php

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlanLimitReachedException;
use App\Http\Middleware\EnsureAccountNotSuspended;
use App\Http\Middleware\EnsureApiWorkspace;
use App\Http\Middleware\EnsureCurrentWorkspace;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleCustomDomain;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            EnsureAccountNotSuspended::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            HandleCustomDomain::class,
        ]);

        // Provider webhooks authenticate with signatures, not session CSRF tokens.
        // Public tour tracking and contact forms must work inside third-party
        // iframes, where cookies (and so CSRF tokens) can be blocked.
        $middleware->validateCsrfTokens(except: ['webhooks/*', 't/*/visits', 't/*/events', 't/*/leads', 'v/*/events']);

        // Custom domains must answer 404 before authentication can redirect to a login page.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: HandleCustomDomain::class);

        $middleware->alias([
            'workspace' => EnsureCurrentWorkspace::class,
            'api.workspace' => EnsureApiWorkspace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (InsufficientCreditsException $exception, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage(), 'errors' => ['credits' => [$exception->getMessage()]]], 402)
                : back()->withErrors(['credits' => $exception->getMessage()])->withInput();
        });

        // Plan limits are validation errors; pages also get a toast explaining them.
        $exceptions->render(function (PlanLimitReachedException $exception, Request $request) {
            if (! $request->expectsJson()) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
            }

            return null;
        });
    })->create();
