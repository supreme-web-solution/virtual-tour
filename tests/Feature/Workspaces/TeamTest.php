<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Carbon\CarbonInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
});

function memberWithRole(Workspace $workspace, WorkspaceRole $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $workspace->addMember($user, $role);
    $user->switchWorkspace($workspace);

    return $user;
}

/**
 * Create an invitation and return its plain token.
 */
function invitationToken(Workspace $workspace, string $email, WorkspaceRole $role = WorkspaceRole::Editor, ?CarbonInterface $expiresAt = null): string
{
    $token = WorkspaceInvitation::newToken();
    $invitation = new WorkspaceInvitation([
        'email' => $email,
        'role' => $role,
        'token_hash' => WorkspaceInvitation::hashToken($token),
        'expires_at' => $expiresAt ?? now()->addDays(7),
    ]);
    $invitation->workspace_id = $workspace->id;
    $invitation->save();

    return $token;
}

test('owners invite people by email, and the token is only stored hashed', function () {
    Notification::fake();

    $this->actingAs($this->owner)
        ->post(route('team.invite'), ['email' => 'New.Person@Example.com', 'role' => 'admin'])
        ->assertSessionHasNoErrors();

    $invitation = WorkspaceInvitation::sole();
    expect($invitation->email)->toBe('new.person@example.com')
        ->and($invitation->role)->toBe(WorkspaceRole::Admin)
        ->and(strlen($invitation->token_hash))->toBe(64);

    Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitationNotification::class, function ($notification, $channels, $notifiable) use ($invitation) {
        return $notifiable->routes['mail'] === 'new.person@example.com'
            && WorkspaceInvitation::hashToken($notification->token) === $invitation->token_hash;
    });
});

test('admins can only invite editors and viewers; existing members cannot be invited', function () {
    Notification::fake();
    $admin = memberWithRole($this->workspace, WorkspaceRole::Admin);
    $editor = memberWithRole($this->workspace, WorkspaceRole::Editor, ['email' => 'editor@example.com']);

    $this->actingAs($admin)->post(route('team.invite'), ['email' => 'x@example.com', 'role' => 'owner'])->assertSessionHasErrors('role');
    $this->actingAs($admin)->post(route('team.invite'), ['email' => 'editor@example.com', 'role' => 'viewer'])->assertSessionHasErrors('email');
    $this->actingAs($editor)->post(route('team.invite'), ['email' => 'y@example.com', 'role' => 'viewer'])->assertForbidden();

    expect(WorkspaceInvitation::count())->toBe(0);
});

test('an invitation joins the workspace when accepted with the invited email', function () {
    $token = invitationToken($this->workspace, 'guest@example.com', WorkspaceRole::Viewer);
    $guest = User::factory()->withWorkspace()->create(['email' => 'guest@example.com']);

    $this->actingAs($guest)
        ->get(route('invitations.show', $token))
        ->assertInertia(fn (Assert $page) => $page->component('invitations/Show')->where('emailMatches', true));

    $this->actingAs($guest)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));

    expect($guest->fresh()->roleIn($this->workspace))->toBe(WorkspaceRole::Viewer)
        ->and($guest->fresh()->current_workspace_id)->toBe($this->workspace->id)
        ->and(WorkspaceInvitation::sole()->accepted_at)->not->toBeNull();

    // A used link cannot be accepted again.
    $this->actingAs($guest)->post(route('invitations.accept', $token))->assertNotFound();
});

test('invitations cannot be accepted by another account, or after expiry', function () {
    $token = invitationToken($this->workspace, 'guest@example.com');
    $expired = invitationToken($this->workspace, 'late@example.com', expiresAt: now()->subDay());
    $someoneElse = User::factory()->withWorkspace()->create(['email' => 'other@example.com']);
    $late = User::factory()->withWorkspace()->create(['email' => 'late@example.com']);

    $this->actingAs($someoneElse)->post(route('invitations.accept', $token))->assertForbidden();
    $this->actingAs($late)->post(route('invitations.accept', $expired))->assertNotFound();

    expect($someoneElse->fresh()->roleIn($this->workspace))->toBeNull();
});

test('guests can open an invitation link and are asked to sign in', function () {
    $token = invitationToken($this->workspace, 'guest@example.com');

    $this->get(route('invitations.show', $token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('signedInAs', null)->where('invitation.email', 'guest@example.com'));
});

test('owners change roles; admins cannot touch other admins or owners', function () {
    $admin = memberWithRole($this->workspace, WorkspaceRole::Admin);
    $otherAdmin = memberWithRole($this->workspace, WorkspaceRole::Admin);
    $editor = memberWithRole($this->workspace, WorkspaceRole::Editor);

    $this->actingAs($this->owner)->patch(route('team.members.update', $editor), ['role' => 'admin'])->assertRedirect();
    expect($editor->fresh()->roleIn($this->workspace))->toBe(WorkspaceRole::Admin);

    $this->actingAs($admin)->patch(route('team.members.update', $otherAdmin), ['role' => 'viewer'])->assertForbidden();
    $this->actingAs($admin)->patch(route('team.members.update', $this->owner), ['role' => 'viewer'])->assertForbidden();
    $this->actingAs($admin)->patch(route('team.members.update', $admin), ['role' => 'owner'])->assertForbidden();
});

test('the last owner cannot be demoted or leave', function () {
    $coOwner = memberWithRole($this->workspace, WorkspaceRole::Owner);

    $this->actingAs($coOwner)->patch(route('team.members.update', $this->owner), ['role' => 'editor'])->assertRedirect();
    expect($this->owner->fresh()->roleIn($this->workspace))->toBe(WorkspaceRole::Editor);

    $this->actingAs($coOwner)->delete(route('team.members.remove', $coOwner))->assertSessionHasErrors('role');
    expect($coOwner->fresh()->roleIn($this->workspace))->toBe(WorkspaceRole::Owner);
});

test('members can leave and be removed', function () {
    $editor = memberWithRole($this->workspace, WorkspaceRole::Editor);
    $viewer = memberWithRole($this->workspace, WorkspaceRole::Viewer);

    $this->actingAs($editor)->delete(route('team.members.remove', $editor))->assertRedirect(route('dashboard'));
    $this->actingAs($this->owner)->delete(route('team.members.remove', $viewer))->assertRedirect();

    expect($editor->fresh()->roleIn($this->workspace))->toBeNull()
        ->and($viewer->fresh()->roleIn($this->workspace))->toBeNull()
        ->and($viewer->fresh()->current_workspace_id)->toBeNull();
});

test('switching workspace requires membership', function () {
    $team = Workspace::factory()->create();
    $team->addMember($this->owner, WorkspaceRole::Editor);
    $stranger = Workspace::factory()->create();

    $this->actingAs($this->owner)->post(route('workspaces.switch', $team))->assertRedirect(route('dashboard'));
    expect($this->owner->fresh()->current_workspace_id)->toBe($team->id);

    $this->actingAs($this->owner->fresh())->post(route('workspaces.switch', $stranger))->assertForbidden();
});

test('the team page lists members and pending invitations', function () {
    memberWithRole($this->workspace, WorkspaceRole::Editor);
    invitationToken($this->workspace, 'pending@example.com');

    $this->actingAs($this->owner)
        ->get(route('team.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('team/Index')
            ->has('members', 2)
            ->has('invitations', 1)
            ->where('can.manage', true));
});
