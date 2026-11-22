<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

test('registering creates a personal workspace owned by the new user', function () {
    $this->post(route('register.store'), [
        'name' => 'Ada Obi',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'ada@example.com')->firstOrFail();

    expect($user->currentWorkspace)
        ->not->toBeNull()
        ->name->toBe("Ada's Workspace")
        ->is_personal->toBeTrue()
        ->and($user->roleIn($user->currentWorkspace))->toBe(WorkspaceRole::Owner);
});

test('a user without a workspace gets one on their next visit', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->currentWorkspace)->not->toBeNull();
});

test('a user removed from their current workspace falls back to another membership', function () {
    $user = User::factory()->withWorkspace()->create();
    $personal = $user->currentWorkspace;

    $team = Workspace::factory()->create();
    $team->addMember($user, WorkspaceRole::Editor);
    $user->switchWorkspace($team);

    $team->members()->detach($user);

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();

    expect($user->fresh()->current_workspace_id)->toBe($personal->id);
});
