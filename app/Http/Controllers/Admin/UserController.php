<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Workspaces\CreatePersonalWorkspace;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage every account on the platform: create, edit, suspend, sign in as,
 * delete, and grant administrator access.
 */
class UserController extends Controller
{
    private const STATUSES = ['all', 'active', 'suspended', 'unverified', 'admins'];

    public function index(Request $request): Response
    {
        $search = trim($request->string('q')->toString());
        $status = in_array($request->string('status')->toString(), self::STATUSES, true)
            ? $request->string('status')->toString()
            : ($request->boolean('admins') ? 'admins' : 'all');

        $listedAdmins = $this->listedAdminEmails();

        $users = User::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($status === 'active', fn ($query) => $query->whereNull('suspended_at'))
            ->when($status === 'suspended', fn ($query) => $query->whereNotNull('suspended_at'))
            ->when($status === 'unverified', fn ($query) => $query->whereNull('email_verified_at'))
            ->when($status === 'admins', fn ($query) => $query->where(fn ($query) => $query
                ->where('is_admin', true)
                ->orWhereIn('email', $listedAdmins)))
            ->with(['workspaces' => fn ($query) => $query->with('currentSubscription.plan')])
            ->withCount('workspaces')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user): array => $this->present($user, $request->user()));

        return Inertia::render('admin/Users', [
            'users' => $users,
            'filters' => ['q' => $search, 'status' => $status],
            'stats' => [
                'total' => User::query()->count(),
                'active' => User::query()->whereNull('suspended_at')->count(),
                'suspended' => User::query()->whereNotNull('suspended_at')->count(),
                'unverified' => User::query()->whereNull('email_verified_at')->count(),
            ],
        ]);
    }

    /**
     * Create an account (with its personal workspace) on someone's behalf.
     */
    public function store(Request $request, CreatePersonalWorkspace $workspaces, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::default()],
            'verified' => ['sometimes', 'boolean'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $user = DB::transaction(function () use ($data, $workspaces): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $user->forceFill([
                'email_verified_at' => ($data['verified'] ?? true) ? now() : null,
                'is_admin' => (bool) ($data['is_admin'] ?? false),
            ])->save();

            $workspaces->handle($user);

            return $user;
        });

        /** @var User $admin */
        $admin = $request->user();
        $activity->log(null, $admin, 'admin.user_created', "Created account for {$user->email}", $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account created for :email.', ['email' => $user->email])]);

        return back();
    }

    /**
     * Edit details, reset the password, verify the email, or change admin access.
     */
    public function update(Request $request, User $user, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', Password::default()],
            'verified' => ['sometimes', 'boolean'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        /** @var User $admin */
        $admin = $request->user();

        if (array_key_exists('is_admin', $data)) {
            if ($user->is($admin) && ! $data['is_admin']) {
                throw ValidationException::withMessages(['is_admin' => __('You cannot remove your own admin access.')]);
            }

            if (! $data['is_admin'] && $user->isListedAdmin()) {
                throw ValidationException::withMessages(['is_admin' => __('This account is an administrator through the ADMIN_EMAILS setting. Remove it there.')]);
            }
        }

        if (isset($data['email']) && $data['email'] !== $user->email && $user->isListedAdmin()) {
            throw ValidationException::withMessages(['email' => __('This email is listed in ADMIN_EMAILS. Update that setting first.')]);
        }

        $changes = [];

        foreach (['name', 'email'] as $field) {
            if (isset($data[$field]) && $data[$field] !== $user->{$field}) {
                $changes[$field] = $data[$field];
            }
        }

        if (filled($data['password'] ?? null)) {
            $changes['password'] = $data['password'];
        }

        if (isset($changes['email'])) {
            $changes['email_verified_at'] = null;
        }

        if (array_key_exists('verified', $data)) {
            $changes['email_verified_at'] = $data['verified']
                ? ($user->email_verified_at ?? now())
                : null;
        }

        $adminChange = array_key_exists('is_admin', $data) && (bool) $data['is_admin'] !== (bool) $user->getRawOriginal('is_admin');

        if (array_key_exists('is_admin', $data)) {
            $changes['is_admin'] = (bool) $data['is_admin'];
        }

        $user->forceFill($changes)->save();

        if ($adminChange) {
            $activity->log(null, $admin, $data['is_admin'] ? 'admin.granted' : 'admin.revoked', ($data['is_admin'] ? 'Granted' : 'Revoked')." admin access for {$user->email}", $user);
            Inertia::flash('toast', ['type' => 'success', 'message' => $data['is_admin'] ? __(':email is now an administrator.', ['email' => $user->email]) : __(':email is no longer an administrator.', ['email' => $user->email])]);
        } else {
            $activity->log(null, $admin, 'admin.user_updated', "Updated account for {$user->email}", $user);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Account updated.')]);
        }

        return back();
    }

    /**
     * Suspend or reinstate an account.
     */
    public function suspension(Request $request, User $user, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate(['suspended' => ['required', 'boolean']]);

        /** @var User $admin */
        $admin = $request->user();

        if ($data['suspended'] && $user->is($admin)) {
            throw ValidationException::withMessages(['suspended' => __('You cannot suspend your own account.')]);
        }

        if ($data['suspended'] && $user->isListedAdmin()) {
            throw ValidationException::withMessages(['suspended' => __('This account is an administrator through ADMIN_EMAILS. Remove it there first.')]);
        }

        $user->forceFill(['suspended_at' => $data['suspended'] ? now() : null])->save();

        $activity->log(null, $admin, $data['suspended'] ? 'admin.user_suspended' : 'admin.user_reinstated', ($data['suspended'] ? 'Suspended' : 'Reinstated')." {$user->email}", $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['suspended'] ? __(':email was suspended.', ['email' => $user->email]) : __(':email was reinstated.', ['email' => $user->email])]);

        return back();
    }

    /**
     * Permanently delete an account and the workspaces it owns.
     */
    public function destroy(Request $request, User $user, ActivityLogger $activity): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        if ($user->is($admin)) {
            throw ValidationException::withMessages(['user' => __('You cannot delete your own account here.')]);
        }

        if ($user->isListedAdmin()) {
            throw ValidationException::withMessages(['user' => __('This account is an administrator through ADMIN_EMAILS. Remove it there first.')]);
        }

        $email = $user->email;

        $user->delete();

        $activity->log(null, $admin, 'admin.user_deleted', "Deleted account {$email}");

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':email was deleted.', ['email' => $email])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, ?User $viewer): array
    {
        $isSelf = $viewer !== null && $user->is($viewer);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'from_env' => $user->isListedAdmin(),
            'suspended' => $user->suspended_at !== null,
            'verified' => $user->email_verified_at !== null,
            'two_factor' => $user->two_factor_confirmed_at !== null,
            'workspaces_count' => (int) $user->getAttribute('workspaces_count'),
            'workspaces' => $user->workspaces->map(fn ($workspace): array => [
                'name' => $workspace->name,
                'role' => $workspace->membership->role->value,
                'plan' => $workspace->currentSubscription?->plan?->name,
            ])->values()->all(),
            'created_at' => $user->created_at?->toIso8601String(),
            'is_you' => $isSelf,
            'can' => [
                'impersonate' => ! $isSelf && $user->suspended_at === null,
                'suspend' => ! $isSelf && ! $user->isListedAdmin(),
                'delete' => ! $isSelf && ! $user->isListedAdmin(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function listedAdminEmails(): array
    {
        return array_values(array_filter(array_map(
            fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) config('app.admin_emails')),
        )));
    }
}
