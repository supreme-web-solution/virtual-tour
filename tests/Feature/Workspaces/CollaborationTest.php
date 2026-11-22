<?php

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Property;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VirtualTour;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->withWorkspace()->create(['name' => 'Olu Owner']);
    $this->workspace = $this->owner->currentWorkspace;
    $this->property = Property::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Lekki Villa']);
});

function teammate(WorkspaceRole $role, string $name = 'Ed Editor'): User
{
    $user = User::factory()->create(['name' => $name]);
    test()->workspace->addMember($user, $role);
    $user->switchWorkspace(test()->workspace);

    return $user;
}

test('editors add team notes that every member can read', function () {
    $editor = teammate(WorkspaceRole::Editor);
    $viewer = teammate(WorkspaceRole::Viewer, 'Vi Viewer');

    $this->actingAs($editor)
        ->post(route('comments.store'), ['subject_type' => 'property', 'subject_id' => $this->property->ulid, 'body' => '  Seller wants the pool shots redone.  '])
        ->assertSessionHasNoErrors();

    $this->actingAs($viewer)
        ->get(route('properties.show', $this->property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('comments.can_comment', false)
            ->where('comments.items.0.body', 'Seller wants the pool shots redone.')
            ->where('comments.items.0.author', 'Ed Editor')
            ->where('comments.items.0.is_mine', false)
            ->where('comments.items.0.can_delete', false));

    $this->post(route('comments.store'), ['subject_type' => 'property', 'subject_id' => $this->property->ulid, 'body' => 'Hi'])->assertForbidden();
});

test('notes work on tours and videos too', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    $video = VideoProject::factory()->inWorkspace($this->workspace)->create();

    $this->actingAs($this->owner);
    $this->post(route('comments.store'), ['subject_type' => 'tour', 'subject_id' => $tour->ulid, 'body' => 'Move the kitchen hotspot.'])->assertSessionHasNoErrors();
    $this->post(route('comments.store'), ['subject_type' => 'video', 'subject_id' => $video->ulid, 'body' => 'Use calmer music.'])->assertSessionHasNoErrors();

    $this->get(route('tours.edit', $tour))->assertInertia(fn (Assert $page) => $page->where('comments.items.0.body', 'Move the kitchen hotspot.'));
    $this->get(route('videos.show', $video))->assertInertia(fn (Assert $page) => $page->where('comments.items.0.body', 'Use calmer music.'));
});

test('authors edit their own notes; owners and admins can delete any note', function () {
    $editor = teammate(WorkspaceRole::Editor);
    $other = teammate(WorkspaceRole::Editor, 'Other Editor');
    $comment = new Comment(['body' => 'First draft']);
    $comment->workspace_id = $this->workspace->id;
    $comment->user_id = $editor->id;
    $comment->commentable()->associate($this->property);
    $comment->save();

    $this->actingAs($other)->patch(route('comments.update', $comment), ['body' => 'Hijacked'])->assertForbidden();
    $this->actingAs($other)->delete(route('comments.destroy', $comment))->assertForbidden();

    $this->actingAs($editor)->patch(route('comments.update', $comment), ['body' => 'Final wording'])->assertSessionHasNoErrors();
    expect($comment->fresh())->body->toBe('Final wording')->edited_at->not->toBeNull();

    $this->actingAs($this->owner)->delete(route('comments.destroy', $comment))->assertSessionHasNoErrors();
    expect(Comment::count())->toBe(0);
});

test('notes cannot be attached to another workspace\'s content', function () {
    $outsider = User::factory()->withWorkspace()->create();

    $this->actingAs($outsider)
        ->post(route('comments.store'), ['subject_type' => 'property', 'subject_id' => $this->property->ulid, 'body' => 'Hello'])
        ->assertNotFound();

    $this->post(route('comments.store'), ['subject_type' => 'workspace', 'subject_id' => 'x', 'body' => 'Hello'])
        ->assertSessionHasErrors('subject_type');
});

test('owners and admins see a filterable activity feed; editors do not', function () {
    $editor = teammate(WorkspaceRole::Editor);
    $logger = app(ActivityLogger::class);
    $logger->log($this->workspace->id, $editor, 'property.created', 'Created property Lekki Villa');
    $logger->log($this->workspace->id, $this->owner, 'team.invited', 'Invited ada@example.com as Viewer');

    $this->actingAs($this->owner)
        ->get(route('team.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activity.data', 2)
            ->where('workspaceInfo.name', $this->workspace->name)
            ->where('workspace.can.manage', true));

    $this->get(route('team.index', ['member' => $editor->id]))
        ->assertInertia(fn (Assert $page) => $page->has('activity.data', 1)->where('activity.data.0.user', 'Ed Editor'));

    $this->get(route('team.index', ['kind' => 'team']))
        ->assertInertia(fn (Assert $page) => $page->has('activity.data', 1)->where('activity.data.0.action', 'team.invited'));

    $this->actingAs($editor)->get(route('team.index'))->assertInertia(fn (Assert $page) => $page->where('activity', null));
});

test('an invitation can be re-sent with a fresh link and a new expiry', function () {
    Notification::fake();
    $invitation = new WorkspaceInvitation([
        'email' => 'ada@example.com',
        'role' => WorkspaceRole::Editor,
        'token_hash' => WorkspaceInvitation::hashToken('old-token'),
        'expires_at' => now()->subDay(),
    ]);
    $invitation->workspace_id = $this->workspace->id;
    $invitation->save();

    $this->actingAs($this->owner)
        ->post(route('team.invitations.resend', $invitation))
        ->assertSessionHasNoErrors();

    $invitation->refresh();
    expect($invitation->expires_at->isFuture())->toBeTrue()
        ->and(WorkspaceInvitation::findValid('old-token'))->toBeNull();

    Notification::assertSentOnDemand(WorkspaceInvitationNotification::class);

    $editor = teammate(WorkspaceRole::Editor);
    $this->actingAs($editor)->post(route('team.invitations.resend', $invitation))->assertForbidden();
});
