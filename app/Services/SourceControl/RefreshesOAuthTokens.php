<?php

namespace App\Services\SourceControl;

use App\Models\SourceControlAccount;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * GitLab and Bitbucket access tokens last about two hours. Before each call
 * the token is swapped for a fresh one, using the refresh token, once it's
 * close to expiring.
 */
trait RefreshesOAuthTokens
{
    /**
     * Ask the provider for a new access token with the account's refresh token.
     */
    abstract protected function requestTokenRefresh(SourceControlAccount $account): Response;

    protected function accessToken(SourceControlAccount $account): string
    {
        if ($account->isTokenExpired() && $account->refresh_token !== null) {
            $response = $this->requestTokenRefresh($account);

            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException("Your {$account->provider->label()} connection has expired. Reconnect the account under Source Control and try again.");
            }

            $expiresIn = $response->json('expires_in');

            $account->update([
                'token' => $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token') ?? $account->refresh_token,
                'token_expires_at' => is_numeric($expiresIn) ? now()->addSeconds((int) $expiresIn) : null,
            ]);
        }

        return $account->token;
    }
}
