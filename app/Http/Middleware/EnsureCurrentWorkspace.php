<?php

namespace App\Http\Middleware;

use App\Actions\Workspaces\CreatePersonalWorkspace;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees the authenticated user has a valid current workspace: falls back
 * to another membership, or creates a personal workspace when they have none.
 */
class EnsureCurrentWorkspace
{
    public function __construct(protected CreatePersonalWorkspace $createPersonalWorkspace) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->roleIn($user->current_workspace_id) === null) {
            $fallback = $user->workspaces()->oldest('workspace_members.created_at')->first();

            $fallback !== null
                ? $user->switchWorkspace($fallback)
                : $this->createPersonalWorkspace->handle($user);
        }

        return $next($request);
    }
}
