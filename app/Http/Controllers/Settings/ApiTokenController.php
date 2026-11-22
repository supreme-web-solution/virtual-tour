<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Entitlement;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API access tokens for the current workspace. A token can only read that
 * workspace's data, and is shown once when it is created.
 */
class ApiTokenController extends Controller
{
    public function __construct(protected EntitlementService $entitlements) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $workspaceId = (int) $user->current_workspace_id;

        // A plan without API access has no trace of the page.
        abort_unless($this->entitlements->allows($workspaceId, Entitlement::ApiAccess), 404);

        return Inertia::render('settings/ApiTokens', [
            'canManage' => $user->roleIn($workspaceId)?->canManage() ?? false,
            'tokens' => $this->tokens($user)->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ])->values(),
            'newToken' => session('api_token'),
            'endpoints' => ['rest' => url('/api/v1'), 'mcp' => url('/api/mcp')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $workspaceId = (int) $user->current_workspace_id;

        abort_unless($user->roleIn($workspaceId)?->canManage(), 403);
        abort_unless($this->entitlements->allows($workspaceId, Entitlement::ApiAccess), 403);

        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        abort_if($this->tokens($user)->count() >= 10, 422, __('You can have up to 10 tokens. Delete one you no longer use.'));

        $token = $user->createToken($data['name'], ['read', "ws:{$workspaceId}"]);

        return to_route('api-tokens.index')->with('api_token', $token->plainTextToken);
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $found = $this->tokens($this->user($request))->firstWhere('id', $token);

        abort_if($found === null, 404);

        $found->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token deleted.')]);

        return back();
    }

    /**
     * The user's tokens for the current workspace only.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    protected function tokens(User $user): Collection
    {
        $ability = 'ws:'.$user->current_workspace_id;

        return $user->tokens()->latest()->get()
            ->filter(fn (PersonalAccessToken $token): bool => in_array($ability, $token->abilities ?? [], true))
            ->values();
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
