<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accepting a workspace invitation. The invitation is tied to an email
 * address: it can only be accepted by an account with that email.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = WorkspaceInvitation::findValid($token);
        $user = $request->user();

        return Inertia::render('invitations/Show', [
            'token' => $token,
            'invitation' => $invitation === null ? null : [
                'workspace' => $invitation->workspace->name,
                'role' => $invitation->role->label(),
                'inviter' => $invitation->inviter?->name,
                'email' => $invitation->email,
            ],
            'signedInAs' => $user?->email,
            'emailMatches' => $invitation !== null && $user !== null && strcasecmp($user->email, $invitation->email) === 0,
        ]);
    }

    public function accept(Request $request, string $token, ActivityLogger $activity): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $invitation = WorkspaceInvitation::findValid($token);

        abort_if($invitation === null, 404);
        abort_unless(strcasecmp($user->email, $invitation->email) === 0, 403);

        DB::transaction(function () use ($invitation, $user): void {
            $invitation->workspace->addMember($user, $invitation->role);
            $invitation->update(['accepted_at' => now()]);
            $user->flushWorkspaceRoles();
            $user->switchWorkspace($invitation->workspace);
        });

        $activity->log($invitation->workspace_id, $user, 'team.joined', "{$user->name} joined as {$invitation->role->label()}");

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Welcome to :workspace.', ['workspace' => $invitation->workspace->name])]);

        return to_route('dashboard');
    }
}
