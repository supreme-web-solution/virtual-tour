<?php

namespace App\Services\Google;

use App\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google's OAuth 2.0 web-server flow, only what Street View publishing
 * needs: send the user to Google, swap the returned code for tokens, and
 * trade the stored refresh token for short-lived access tokens.
 *
 * @see https://developers.google.com/identity/protocols/oauth2/web-server
 */
class GoogleOAuth
{
    public const STREET_VIEW_SCOPE = 'https://www.googleapis.com/auth/streetviewpublish';

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function redirectUri(): string
    {
        return route('google.callback');
    }

    public function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email '.self::STREET_VIEW_SCOPE,
            'access_type' => 'offline',
            // Ask every time so Google always returns a refresh token.
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * @return array{refresh_token: string, email: string|null}
     */
    public function exchange(string $code): array
    {
        $tokens = $this->post([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);

        $refresh = (string) ($tokens['refresh_token'] ?? '');
        $scopes = (string) ($tokens['scope'] ?? '');

        if ($refresh === '' || ! str_contains($scopes, self::STREET_VIEW_SCOPE)) {
            throw new ProviderException('Google did not grant Street View access. Please try again and allow every permission.');
        }

        $email = null;

        try {
            $email = Http::withToken((string) $tokens['access_token'])->timeout(15)
                ->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');
        } catch (ConnectionException) {
            // The address is only shown to the user; the connection works without it.
        }

        return ['refresh_token' => $refresh, 'email' => is_string($email) ? $email : null];
    }

    public function accessToken(string $refreshToken): string
    {
        $tokens = $this->post(['refresh_token' => $refreshToken, 'grant_type' => 'refresh_token']);

        return (string) ($tokens['access_token'] ?? throw new ProviderException('Google did not return an access token.', retryable: true));
    }

    /**
     * Revoke a refresh token at Google (best effort).
     */
    public function revoke(string $refreshToken): void
    {
        try {
            Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/revoke', ['token' => $refreshToken]);
        } catch (ConnectionException) {
            // Disconnecting here still works; the user can also revoke it in their Google account.
        }
    }

    /**
     * @param  array<string, string>  $body
     * @return array<string, mixed>
     */
    protected function post(array $body): array
    {
        try {
            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                ...$body,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
            ]);
        } catch (ConnectionException $exception) {
            throw new ProviderException('Could not reach Google.', retryable: true, previous: $exception);
        }

        if ($response->failed()) {
            // invalid_grant means the user revoked access or the token expired: reconnecting is the fix.
            $reason = (string) $response->json('error');

            throw new ProviderException(
                $reason === 'invalid_grant'
                    ? 'The Google connection expired. Reconnect Google and try again.'
                    : 'Google refused the request ('.($reason ?: $response->status()).').',
                retryable: $response->status() >= 500,
                status: $response->status(),
            );
        }

        return (array) $response->json();
    }
}
