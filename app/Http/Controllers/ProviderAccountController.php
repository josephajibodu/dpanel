<?php

namespace App\Http\Controllers;

use App\Actions\Servers\FetchProviderCatalogAction;
use App\Enums\Provider;
use App\Exceptions\ProviderApiException;
use App\Http\Requests\StoreProviderAccountRequest;
use App\Http\Resources\ProviderAccountResource;
use App\Jobs\ValidateProviderJob;
use App\Models\ProviderAccount;
use App\Models\Team;
use App\Services\Providers\ProviderManager;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProviderAccountController extends Controller
{
    public function index(Team $team): Response
    {
        $accounts = $team->providerAccounts()
            ->withCount('servers')
            ->latest()
            ->get();

        return Inertia::render('provider-accounts/index', [
            'accounts' => ProviderAccountResource::collection($accounts),
        ]);
    }

    public function create(Team $team): Response
    {
        $this->authorize('create', [ProviderAccount::class, $team]);

        return Inertia::render('provider-accounts/create', [
            'providers' => collect(Provider::cases())->map(fn ($p) => [
                'value' => $p->value,
                'label' => $p->label(),
            ]),
        ]);
    }

    public function store(Team $team, StoreProviderAccountRequest $request, ProviderManager $providerManager): RedirectResponse
    {
        $validated = $request->validated();
        $credentials = $request->credentials();

        $provider = $providerManager->driver($validated['provider']);
        $provider->setCredentials($credentials);

        $isValid = $provider->validateCredentials();

        $team->providerAccounts()->create([
            'user_id' => auth()->id(),
            'provider' => $validated['provider'],
            'name' => $validated['name'],
            'credentials' => $credentials,
            'is_valid' => $isValid,
            'validated_at' => $isValid ? now() : null,
        ]);

        if (! $isValid) {
            return redirect()
                ->route('provider-accounts.index', $team)
                ->with('error', 'Provider account created but credentials could not be validated. Please check your credentials.');
        }

        return redirect()
            ->route('provider-accounts.index', $team)
            ->with('success', 'Provider account connected successfully.');
    }

    public function show(Team $team, ProviderAccount $providerAccount): Response
    {
        $this->authorize('view', $providerAccount);

        $providerAccount->load(['servers' => fn ($q) => $q->latest()->limit(10)]);
        $providerAccount->loadCount('servers');

        return Inertia::render('provider-accounts/show', [
            'account' => new ProviderAccountResource($providerAccount),
        ]);
    }

    public function destroy(Team $team, ProviderAccount $providerAccount): RedirectResponse
    {
        $this->authorize('delete', $providerAccount);

        if ($providerAccount->servers()->exists()) {
            return redirect()
                ->route('provider-accounts.index', $team)
                ->with('error', 'Cannot delete provider account with active servers.');
        }

        $providerAccount->delete();

        return redirect()
            ->route('provider-accounts.index', $team)
            ->with('success', 'Provider account disconnected.');
    }

    public function validate(Team $team, ProviderAccount $providerAccount): RedirectResponse
    {
        $this->authorize('update', $providerAccount);

        ValidateProviderJob::dispatch($providerAccount);

        return redirect()
            ->route('provider-accounts.index', $team)
            ->with('success', 'Credentials validation started.');
    }

    /**
     * Return the regions and sizes this account can create servers with.
     *
     * The create-server page calls this after it has loaded, once the user
     * picks an account, so a slow provider API never blocks the page itself.
     */
    public function catalog(Team $team, ProviderAccount $providerAccount, FetchProviderCatalogAction $fetchCatalog): JsonResponse
    {
        $this->authorize('view', $providerAccount);

        try {
            return response()->json($fetchCatalog->execute($providerAccount));
        } catch (Throwable $exception) {
            report($exception);

            $label = $providerAccount->provider->label();
            $status = $exception instanceof ProviderApiException ? $exception->statusCode : null;
            $status ??= $exception instanceof RequestException ? $exception->response->status() : null;

            return response()->json([
                'message' => in_array($status, [401, 403], true)
                    ? "{$label} rejected this account's API key. Update the key in Provider accounts, then try again."
                    : "Couldn't load regions and sizes from {$label}. Try again in a moment.",
            ], 502);
        }
    }
}
