<?php

namespace App\Http\Middleware;

use App\Enums\Entitlement;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use App\Services\Credits\CreditService;
use App\Support\ProviderConfiguration;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Absolute, so links to the platform work from custom domains too.
            'appUrl' => rtrim((string) config('app.url'), '/'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'workspace' => fn (): ?array => $this->currentWorkspace($request),
            'workspaces' => fn (): array => $request->user()
                ? $request->user()->workspaces()->orderBy('name')->get()
                    ->map(fn (Workspace $workspace): array => [
                        'id' => $workspace->ulid,
                        'name' => $workspace->name,
                        'role' => $workspace->membership->role->value,
                    ])->values()->all()
                : [],
            'impersonating' => fn (): bool => $request->hasSession() && $request->session()->has('impersonator_id'),
            'features' => fn (): array => app(ProviderConfiguration::class)->features(),
        ];
    }

    /**
     * The active workspace and the user's permissions in it.
     *
     * @return array{id: string, name: string, role: string, credits: int, can: array{edit: bool, manage: bool}}|null
     */
    protected function currentWorkspace(Request $request): ?array
    {
        $user = $request->user();
        $workspace = $user?->currentWorkspace;
        $role = $user?->roleIn($workspace);

        if ($workspace === null || $role === null) {
            return null;
        }

        $entitlements = app(EntitlementService::class);

        return [
            'id' => $workspace->ulid,
            'name' => $workspace->name,
            'role' => $role->value,
            'credits' => app(CreditService::class)->available($workspace->id),
            'can' => [
                'edit' => $role->canEdit(),
                'manage' => $role->canManage(),
            ],
            'plan' => [
                'name' => $entitlements->plan($workspace->id)->name ?? null,
                'features' => collect(Entitlement::features())
                    ->mapWithKeys(fn (Entitlement $feature): array => [$feature->value => $entitlements->allows($workspace->id, $feature)])
                    ->all(),
            ],
        ];
    }
}
