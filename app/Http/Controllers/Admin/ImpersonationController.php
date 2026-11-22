<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Lets a platform administrator see the app as another user ("sign in as"),
 * and return to their own account afterwards.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, User $user, ActivityLogger $activity): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        if ($user->is($admin)) {
            throw ValidationException::withMessages(['user' => __('You are already signed in as yourself.')]);
        }

        if ($user->suspended_at !== null) {
            throw ValidationException::withMessages(['user' => __('Reinstate this account before signing in as them.')]);
        }

        $activity->log(null, $admin, 'admin.impersonation_started', "Signed in as {$user->email}", $user);

        Auth::guard('web')->login($user);

        $request->session()->put(self::SESSION_KEY, $admin->id);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull(self::SESSION_KEY);
        $admin = $adminId ? User::query()->whereKey($adminId)->first() : null;

        if ($admin === null || ! $admin->is_admin) {
            return redirect()->route('dashboard');
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.users.index');
    }
}
