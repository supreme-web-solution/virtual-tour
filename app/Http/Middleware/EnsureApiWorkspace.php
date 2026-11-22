<?php

namespace App\Http\Middleware;

use App\Enums\Entitlement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins an API request to the one workspace its token was issued for (stored
 * as a "ws:{id}" ability), and checks the user is still a member and the
 * workspace plan still includes API access.
 */
class EnsureApiWorkspace
{
    public function __construct(protected EntitlementService $entitlements) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspaceId = $user instanceof User ? $this->workspaceId($user) : null;
        $workspace = $workspaceId === null ? null : Workspace::find($workspaceId);

        if (! $user instanceof User || $workspace === null || $user->suspended_at !== null || $user->roleIn($workspace) === null) {
            return response()->json(['message' => 'This token no longer has access to its workspace.'], 403);
        }

        if (! $this->entitlements->allows($workspace->id, Entitlement::ApiAccess)) {
            return response()->json(['message' => 'The workspace plan does not include API access.'], 403);
        }

        // In memory only: the user's saved current workspace is left alone.
        $user->current_workspace_id = $workspace->id;
        $user->setRelation('currentWorkspace', $workspace);

        return $next($request);
    }

    protected function workspaceId(User $user): ?int
    {
        foreach ($user->currentAccessToken()->abilities ?? [] as $ability) {
            if (is_string($ability) && preg_match('/^ws:(\d+)$/', $ability, $match)) {
                return (int) $match[1];
            }
        }

        return null;
    }
}
