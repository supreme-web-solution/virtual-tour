<?php

namespace App\Http\Controllers;

use App\Enums\Entitlement;
use App\Exceptions\ProviderException;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Google\GoogleOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Link a workspace to one Google account for Street View publishing.
 */
class GoogleConnectionController extends Controller
{
    public function __construct(protected GoogleOAuth $oauth, protected EntitlementService $entitlements) {}

    public function connect(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $this->authorizeManage($user);

        if (! $this->oauth->isConfigured()) {
            return $this->back($request, __('Google sign-in is not set up yet. An administrator must add the Google client ID and secret.'), 'error');
        }

        $return = (string) $request->query('return', '');
        $state = Str::random(40);

        $request->session()->put('google_oauth', [
            'state' => $state,
            'workspace_id' => $user->current_workspace_id,
            // Only ever come back to a page of this app.
            'return' => str_starts_with($return, '/') && ! str_starts_with($return, '//') ? $return : route('dashboard', absolute: false),
        ]);

        return redirect()->away($this->oauth->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $flow = $request->session()->pull('google_oauth');

        if (! is_array($flow) || ! hash_equals((string) $flow['state'], (string) $request->query('state')) || (int) $flow['workspace_id'] !== (int) $user->current_workspace_id) {
            return redirect()->route('dashboard');
        }

        $this->authorizeManage($user);

        if ($request->filled('error') || ! $request->filled('code')) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Google access was not granted.')]);

            return redirect()->to($flow['return']);
        }

        try {
            $tokens = $this->oauth->exchange((string) $request->query('code'));
        } catch (ProviderException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return redirect()->to($flow['return']);
        }

        $connection = GoogleConnection::where('workspace_id', $user->current_workspace_id)->first() ?? new GoogleConnection;
        $connection->workspace_id = (int) $user->current_workspace_id;
        $connection->connected_by = $user->id;
        $connection->google_email = $tokens['email'];
        $connection->refresh_token = $tokens['refresh_token'];
        $connection->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Google account connected.')]);

        return redirect()->to($flow['return']);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $this->authorizeManage($user);

        $connection = GoogleConnection::where('workspace_id', $user->current_workspace_id)->first();

        if ($connection !== null) {
            $this->oauth->revoke($connection->refresh_token);
            $connection->delete();
        }

        return $this->back($request, __('Google account disconnected.'), 'success');
    }

    protected function authorizeManage(User $user): void
    {
        $workspaceId = (int) $user->current_workspace_id;

        abort_unless($user->roleIn($workspaceId)?->canManage(), 403);
        abort_unless($this->entitlements->allows($workspaceId, Entitlement::StreetView), 403);
    }

    protected function back(Request $request, string $message, string $type): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
