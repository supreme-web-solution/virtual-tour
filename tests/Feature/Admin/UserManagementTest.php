<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->withWorkspace()->create();
    $this->admin->forceFill(['is_admin' => true])->save();

    $this->member = User::factory()->withWorkspace()->create();
});

test('guests and non-admins cannot manage users', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));

    $this->actingAs($this->member)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($this->member)->delete(route('admin.users.destroy', $this->admin))->assertForbidden();
    $this->actingAs($this->member)->post(route('admin.users.impersonate', $this->admin))->assertForbidden();
});

test('the users page lists accounts and filters by status', function () {
    User::factory()->suspended()->create(['email' => 'blocked@example.com']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['status' => 'suspended']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Users')
            ->where('stats.suspended', 1)
            ->has('users.data', 1)
            ->where('users.data.0.email', 'blocked@example.com'));
});

test('an admin can create a user with a personal workspace', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), [
        'name' => 'New Person',
        'email' => 'new@example.com',
        'password' => 'a-Very-Strong-Pass-123!',
        'verified' => true,
    ])->assertRedirect();

    $user = User::where('email', 'new@example.com')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->workspaces()->count())->toBe(1);
});

test('an admin can edit a user and keep the password when left blank', function () {
    $hash = $this->member->password;

    $this->actingAs($this->admin)->patch(route('admin.users.update', $this->member), [
        'name' => 'Renamed',
        'email' => $this->member->email,
        'password' => '',
        'is_admin' => true,
    ])->assertRedirect();

    $this->member->refresh();

    expect($this->member->name)->toBe('Renamed')
        ->and($this->member->password)->toBe($hash)
        ->and($this->member->is_admin)->toBeTrue();
});

test('admins cannot remove their own access or listed admins', function () {
    $this->actingAs($this->admin)->patch(route('admin.users.update', $this->admin), ['is_admin' => false])
        ->assertSessionHasErrors('is_admin');

    config(['app.admin_emails' => $this->member->email]);

    $this->actingAs($this->admin)->patch(route('admin.users.suspension', $this->member), ['suspended' => true])
        ->assertSessionHasErrors('suspended');
    $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->member))
        ->assertSessionHasErrors('user');

    expect($this->member->fresh())->not->toBeNull();
});

test('a suspended account is signed out and cannot continue', function () {
    $this->actingAs($this->admin)->patch(route('admin.users.suspension', $this->member), ['suspended' => true])
        ->assertRedirect();

    expect($this->member->fresh()->suspended_at)->not->toBeNull();

    $this->actingAs($this->member->fresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();

    $this->actingAs($this->admin)->patch(route('admin.users.suspension', $this->member), ['suspended' => false]);

    expect($this->member->fresh()->suspended_at)->toBeNull();
});

test('an admin can delete another user but not themselves', function () {
    $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->admin))
        ->assertSessionHasErrors('user');

    $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->member))->assertRedirect();

    expect(User::find($this->member->id))->toBeNull();
});

test('an admin can sign in as a user and return', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.users.impersonate', $this->member))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->member);

    $this->post(route('impersonation.stop'))->assertRedirect(route('admin.users.index'));

    $this->assertAuthenticatedAs($this->admin);
});

test('impersonation guards: yourself and suspended accounts', function () {
    $this->actingAs($this->admin)->post(route('admin.users.impersonate', $this->admin))
        ->assertSessionHasErrors('user');

    $this->member->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($this->admin)->post(route('admin.users.impersonate', $this->member))
        ->assertSessionHasErrors('user');

    $this->assertAuthenticatedAs($this->admin);
});

test('stopping without an impersonation session does nothing harmful', function () {
    $this->actingAs($this->member)->post(route('impersonation.stop'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->member);
});
