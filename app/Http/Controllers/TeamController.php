<?php

namespace App\Http\Controllers;

use App\Enums\Entitlement;
use App\Enums\WorkspaceRole;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use App\Services\ActivityLogger;
use App\Services\Billing\EntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace members and invitations.
 *
 * Owners manage everyone. Admins manage editors and viewers only. Nobody can
 * change their own role, and a workspace always keeps at least one owner.
 */
class TeamController extends Controller
{
    public function __construct(protected ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $workspace = $this->workspace($user);
        $myRole = $user->roleIn($workspace);

        return Inertia::render('team/Index', [
            // Not "workspace": that is the shared current-workspace prop.
            'workspaceInfo' => ['id' => $workspace->ulid, 'name' => $workspace->name, 'is_personal' => $workspace->is_personal],
            'members' => $workspace->members()->orderBy('name')->get()->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->membership->role->value,
                'joined_at' => $member->membership->created_at?->toIso8601String(),
                'is_you' => $member->id === $user->id,
                'can_manage' => $this->canManageMember($myRole, $member->membership->role) && $member->id !== $user->id,
            ])->values(),
            'invitations' => $myRole?->canManage()
                ? WorkspaceInvitation::where('workspace_id', $workspace->id)->whereNull('accepted_at')->latest()->get()
                    ->map(fn (WorkspaceInvitation $invitation): array => [
                        'id' => $invitation->id,
                        'email' => $invitation->email,
                        'role' => $invitation->role->value,
                        'expired' => $invitation->expires_at->isPast(),
                        'expires_at' => $invitation->expires_at->toIso8601String(),
                    ])->values()
                : [],
            'assignableRoles' => array_map(
                fn (WorkspaceRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                $this->assignableRoles($myRole),
            ),
            'myRole' => $myRole?->value,
            'can' => [
                'manage' => (bool) $myRole?->canManage(),
                'rename' => (bool) $myRole?->canManage(),
            ],
            'activity' => $myRole?->canManage() ? $this->activityFeed($request, $workspace) : null,
            'activityKinds' => array_map(fn (string $kind): array => ['value' => $kind, 'label' => ucfirst($kind)], array_keys(self::ACTIVITY_KINDS)),
            'activityFilters' => [
                'member' => $request->string('member')->toString(),
                'kind' => $request->string('kind')->toString(),
            ],
        ]);
    }

    /**
     * Send a pending (or expired) invitation again with a fresh link.
     */
    public function resendInvitation(Request $request, WorkspaceInvitation $invitation): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($invitation->workspace_id === $user->current_workspace_id && (bool) $user->roleIn($invitation->workspace_id)?->canManage(), 403);
        abort_if($invitation->accepted_at !== null, 404);

        $token = WorkspaceInvitation::newToken();
        $invitation->update([
            'token_hash' => WorkspaceInvitation::hashToken($token),
            'expires_at' => now()->addDays((int) config('analytics.invitation_expiry_days', 7)),
        ]);

        Notification::route('mail', $invitation->email)->notify(new WorkspaceInvitationNotification($invitation, $token));
        $this->activity->log($invitation->workspace_id, $user, 'team.invited', "Re-sent the invitation to {$invitation->email}");

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation re-sent to :email. The old link no longer works.', ['email' => $invitation->email])]);

        return back();
    }

    /**
     * Activity action prefixes grouped for filtering.
     *
     * @var array<string, list<string>>
     */
    public const ACTIVITY_KINDS = [
        'content' => ['property.', 'tour.', 'media.', 'video.', 'comment.'],
        'team' => ['team.', 'workspace.'],
        'enquiries' => ['lead.'],
        'billing' => ['subscription.', 'credits.'],
    ];

    /**
     * @return LengthAwarePaginator<int, array{id: int, action: string, description: string, user: string|null, created_at: string}>
     */
    protected function activityFeed(Request $request, Workspace $workspace): LengthAwarePaginator
    {
        $member = $request->integer('member');
        $prefixes = self::ACTIVITY_KINDS[$request->string('kind')->toString()] ?? null;

        return ActivityLog::where('workspace_id', $workspace->id)
            ->when($member > 0, fn ($query) => $query->where('user_id', $member))
            ->when($prefixes !== null, fn ($query) => $query->where(function ($query) use ($prefixes): void {
                foreach ((array) $prefixes as $prefix) {
                    $query->orWhere('action', 'like', $prefix.'%');
                }
            }))
            ->with('user:id,name')
            ->latest('id')
            ->paginate(20, pageName: 'activity_page')
            ->withQueryString()
            ->through(fn (ActivityLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'user' => $log->user?->name,
                'created_at' => $log->created_at->toIso8601String(),
            ]);
    }

    public function rename(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $workspace = $this->workspace($user);
        abort_unless((bool) $user->roleIn($workspace)?->canManage(), 403);

        $workspace->update($request->validate(['name' => ['required', 'string', 'max:120']]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace renamed.')]);

        return back();
    }

    public function invite(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $workspace = $this->workspace($user);
        $myRole = $user->roleIn($workspace);
        abort_unless((bool) $myRole?->canManage(), 403);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(array_map(fn (WorkspaceRole $role): string => $role->value, $this->assignableRoles($myRole)))],
        ]);

        $email = strtolower(trim($data['email']));

        if ($workspace->members()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => __('This person is already a member.')]);
        }

        // One pending invitation per email: a new invite replaces the old one.
        WorkspaceInvitation::where('workspace_id', $workspace->id)->where('email', $email)->whereNull('accepted_at')->delete();

        app(EntitlementService::class)->ensureCanAdd($workspace->id, Entitlement::TeamMembers);

        $token = WorkspaceInvitation::newToken();
        $invitation = new WorkspaceInvitation([
            'invited_by' => $user->id,
            'email' => $email,
            'role' => WorkspaceRole::from($data['role']),
            'token_hash' => WorkspaceInvitation::hashToken($token),
            'expires_at' => now()->addDays((int) config('analytics.invitation_expiry_days', 7)),
        ]);
        $invitation->workspace_id = $workspace->id;
        $invitation->save();

        Notification::route('mail', $email)->notify(new WorkspaceInvitationNotification($invitation, $token));

        $this->activity->log($workspace->id, $user, 'team.invited', "Invited {$email} as {$invitation->role->label()}");

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $email])]);

        return back();
    }

    public function revokeInvitation(Request $request, WorkspaceInvitation $invitation): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($invitation->workspace_id === $user->current_workspace_id && (bool) $user->roleIn($invitation->workspace_id)?->canManage(), 403);

        $invitation->delete();

        return back();
    }

    public function updateRole(Request $request, User $member): RedirectResponse
    {
        $user = $this->user($request);
        $workspace = $this->workspace($user);
        $myRole = $user->roleIn($workspace);
        $theirRole = $member->roleIn($workspace);

        abort_unless($theirRole !== null && $member->id !== $user->id && $this->canManageMember($myRole, $theirRole), 403);

        $data = $request->validate([
            'role' => ['required', Rule::in(array_map(fn (WorkspaceRole $role): string => $role->value, $this->assignableRoles($myRole)))],
        ]);
        $newRole = WorkspaceRole::from($data['role']);

        if ($theirRole === WorkspaceRole::Owner && $newRole !== WorkspaceRole::Owner) {
            $this->ensureAnotherOwner($workspace, $member);
        }

        $workspace->addMember($member, $newRole);
        $this->activity->log($workspace->id, $user, 'team.role_changed', "Changed {$member->name}'s role to {$newRole->label()}");

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return back();
    }

    /**
     * Remove a member, or leave the workspace when removing yourself.
     */
    public function remove(Request $request, User $member): RedirectResponse
    {
        $user = $this->user($request);
        $workspace = $this->workspace($user);
        $theirRole = $member->roleIn($workspace);
        $leaving = $member->id === $user->id;

        abort_unless($theirRole !== null && ($leaving || $this->canManageMember($user->roleIn($workspace), $theirRole)), 403);

        if ($theirRole === WorkspaceRole::Owner) {
            $this->ensureAnotherOwner($workspace, $member);
        }

        $workspace->members()->detach($member->id);

        if ($member->current_workspace_id === $workspace->id) {
            $member->forceFill(['current_workspace_id' => null])->save();
        }

        $this->activity->log($workspace->id, $user, 'team.removed', $leaving ? "{$member->name} left the workspace" : "Removed {$member->name}");

        if ($leaving) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('You left :workspace.', ['workspace' => $workspace->name])]);

            return to_route('dashboard');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return back();
    }

    /**
     * Switch the active workspace to another one the user belongs to.
     */
    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->roleIn($workspace) !== null, 403);

        $user->switchWorkspace($workspace);

        return to_route('dashboard');
    }

    protected function canManageMember(?WorkspaceRole $actor, WorkspaceRole $target): bool
    {
        return match ($actor) {
            WorkspaceRole::Owner => true,
            WorkspaceRole::Admin => in_array($target, [WorkspaceRole::Editor, WorkspaceRole::Viewer], true),
            default => false,
        };
    }

    /**
     * @return list<WorkspaceRole>
     */
    protected function assignableRoles(?WorkspaceRole $actor): array
    {
        return match ($actor) {
            WorkspaceRole::Owner => WorkspaceRole::cases(),
            WorkspaceRole::Admin => [WorkspaceRole::Editor, WorkspaceRole::Viewer],
            default => [],
        };
    }

    protected function ensureAnotherOwner(Workspace $workspace, User $member): void
    {
        $otherOwners = $workspace->members()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->where('users.id', '!=', $member->id)
            ->exists();

        if (! $otherOwners) {
            throw ValidationException::withMessages(['role' => __('A workspace needs at least one owner. Make someone else an owner first.')]);
        }
    }

    protected function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    protected function workspace(User $user): Workspace
    {
        return Workspace::findOrFail($user->current_workspace_id);
    }
}
