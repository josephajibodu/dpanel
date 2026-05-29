<?php

namespace App\Http\Controllers;

use App\Enums\RepositoryProvider;
use App\Http\Resources\SourceControlAccountResource;
use App\Models\SourceControlAccount;
use App\Services\SourceControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;

class SourceControlAccountController extends Controller
{
    public function index(): Response
    {
        $accounts = auth()->user()
            ->sourceControlAccounts()
            ->latest()
            ->get();

        return Inertia::render('source-control/index', [
            'accounts' => SourceControlAccountResource::collection($accounts),
            'providers' => collect(RepositoryProvider::cases())->filter(fn ($p) => $p !== RepositoryProvider::Custom)->map(fn ($p) => [
                'value' => $p->value,
                'label' => $p->label(),
            ]),
        ]);
    }

    /**
     * Redirect to provider's OAuth authorization page.
     */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        if (! in_array($provider, ['github', 'gitlab', 'bitbucket'])) {
            abort(404);
        }

        // Store the intended redirect URL in session. The optional popup flag
        // makes the callback close the OAuth popup instead of redirecting, so
        // the opener page can refresh in place (used by the create-site flow).
        session()->put('source_control_redirect', $request->get('redirect', route('source-control.index')));
        session()->put('source_control_popup', $request->boolean('popup'));

        $socialite = match ($provider) {
            'github' => Socialite::driver('github')->scopes(['repo', 'read:user', 'admin:public_key']),
            'gitlab' => Socialite::driver('gitlab')->scopes(['api', 'read_user']),
            'bitbucket' => Socialite::driver('bitbucket')->scopes(['repository', 'account']),
            default => throw new \InvalidArgumentException("Unsupported provider: {$provider}"),
        };

        return $socialite->redirect();
    }

    /**
     * Handle OAuth callback from provider.
     */
    public function callback(Request $request, string $provider): RedirectResponse|HttpResponse
    {
        if (! in_array($provider, ['github', 'gitlab', 'bitbucket'])) {
            abort(404);
        }

        $redirectUrl = session()->pull('source_control_redirect', route('source-control.index'));
        $isPopup = (bool) session()->pull('source_control_popup', false);

        try {
            $socialite = match ($provider) {
                'github' => Socialite::driver('github'),
                'gitlab' => Socialite::driver('gitlab'),
                'bitbucket' => Socialite::driver('bitbucket'),
                default => throw new \InvalidArgumentException("Unsupported provider: {$provider}"),
            };

            $user = $socialite->user();

            // Map provider user data
            $providerEnum = match ($provider) {
                'github' => RepositoryProvider::Github,
                'gitlab' => RepositoryProvider::Gitlab,
                'bitbucket' => RepositoryProvider::Bitbucket,
            };

            // Check if account already exists
            $account = SourceControlAccount::where('user_id', auth()->id())
                ->where('provider', $providerEnum)
                ->where('provider_user_id', $user->getId())
                ->first();

            if ($account) {
                // Update existing account
                $account->update([
                    'provider_username' => $user->getNickname() ?? $user->getName(),
                    'name' => $user->getName(),
                    'email' => $user->getEmail(),
                    'avatar_url' => $user->getAvatar(),
                    'token' => $user->token,
                    'refresh_token' => $user->refreshToken,
                    'token_expires_at' => $user->expiresIn ? now()->addSeconds($user->expiresIn) : null,
                    'connected_at' => now(),
                ]);
            } else {
                // Create new account
                $account = auth()->user()->sourceControlAccounts()->create([
                    'provider' => $providerEnum,
                    'provider_user_id' => (string) $user->getId(),
                    'provider_username' => $user->getNickname() ?? $user->getName(),
                    'name' => $user->getName(),
                    'email' => $user->getEmail(),
                    'avatar_url' => $user->getAvatar(),
                    'token' => $user->token,
                    'refresh_token' => $user->refreshToken,
                    'token_expires_at' => $user->expiresIn ? now()->addSeconds($user->expiresIn) : null,
                    'connected_at' => now(),
                ]);
            }

            if ($isPopup) {
                return $this->popupCloseResponse(true);
            }

            return redirect($redirectUrl)
                ->with('success', ucfirst($provider).' account connected successfully.');
        } catch (\Exception $e) {
            if ($isPopup) {
                return $this->popupCloseResponse(false, $e->getMessage());
            }

            return redirect($redirectUrl)
                ->with('error', 'Failed to connect '.ucfirst($provider).' account: '.$e->getMessage());
        }
    }

    /**
     * Return an HTML response for OAuth popup windows: close the popup and
     * post a message to the opener so the create-site page can refresh its
     * connected-accounts list in place.
     */
    private function popupCloseResponse(bool $success, ?string $error = null): HttpResponse
    {
        $payload = json_encode([
            'type' => 'flitops:source-control',
            'status' => $success ? 'connected' : 'failed',
            'error' => $error,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Connecting…</title></head>
<body>
<script>
  (function () {
    try {
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage({$payload}, window.location.origin);
      }
    } catch (e) {}
    window.close();
  })();
</script>
</body></html>
HTML;

        return response($html);
    }

    public function destroy(SourceControlAccount $sourceControlAccount): RedirectResponse
    {
        $this->authorize('delete', $sourceControlAccount);

        $providerLabel = $sourceControlAccount->provider->label();

        $sourceControlAccount->delete();

        return redirect()
            ->route('source-control.index')
            ->with('success', "{$providerLabel} account disconnected.");
    }

    public function repositories(
        SourceControlAccount $sourceControlAccount,
        SourceControlService $sourceControlService,
    ): JsonResponse {
        $this->authorize('view', $sourceControlAccount);

        $repositories = $sourceControlService->listRepositories($sourceControlAccount);

        return response()->json([
            'repositories' => $repositories->all(),
        ]);
    }

    public function branches(
        SourceControlAccount $sourceControlAccount,
        string $repository,
        SourceControlService $sourceControlService,
    ): JsonResponse {
        $this->authorize('view', $sourceControlAccount);

        // Decode the repository name in case it's URL-encoded
        $repository = urldecode($repository);

        $branches = $sourceControlService->listBranches($sourceControlAccount, $repository);

        return response()->json([
            'branches' => $branches->map(fn ($branch) => [
                'name' => $branch['name'],
                'protected' => $branch['protected'],
            ]),
        ]);
    }
}
