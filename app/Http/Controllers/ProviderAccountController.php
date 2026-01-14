<?php

namespace App\Http\Controllers;

use App\Enums\Provider;
use App\Http\Requests\StoreProviderAccountRequest;
use App\Http\Resources\ProviderAccountResource;
use App\Jobs\ValidateProviderJob;
use App\Models\ProviderAccount;
use App\Services\Providers\ProviderManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProviderAccountController extends Controller
{
    public function index(): Response
    {
        $accounts = auth()->user()
            ->providerAccounts()
            ->withCount('servers')
            ->latest()
            ->get();

        return Inertia::render('provider-accounts/index', [
            'accounts' => ProviderAccountResource::collection($accounts),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('provider-accounts/create', [
            'providers' => collect(Provider::cases())->map(fn ($p) => [
                'value' => $p->value,
                'label' => $p->label(),
            ]),
        ]);
    }

    public function store(StoreProviderAccountRequest $request, ProviderManager $providerManager): RedirectResponse
    {
        $validated = $request->validated();

        // Validate credentials before storing
        $provider = $providerManager->driver($validated['provider']);
        $provider->setCredentials(['api_token' => $validated['api_token']]);

        $isValid = $provider->validateCredentials();

        $account = auth()->user()->providerAccounts()->create([
            'provider' => $validated['provider'],
            'name' => $validated['name'],
            'credentials' => ['api_token' => $validated['api_token']],
            'is_valid' => $isValid,
            'validated_at' => $isValid ? now() : null,
        ]);

        if (! $isValid) {
            return redirect()
                ->route('provider-accounts.index')
                ->with('error', 'Provider account created but credentials could not be validated. Please check your API token.');
        }

        return redirect()
            ->route('provider-accounts.index')
            ->with('success', 'Provider account connected successfully.');
    }

    public function show(ProviderAccount $providerAccount): Response
    {
        $this->authorize('view', $providerAccount);

        $providerAccount->load(['servers' => fn ($q) => $q->latest()->limit(10)]);
        $providerAccount->loadCount('servers');

        return Inertia::render('provider-accounts/show', [
            'account' => new ProviderAccountResource($providerAccount),
        ]);
    }

    public function destroy(ProviderAccount $providerAccount): RedirectResponse
    {
        $this->authorize('delete', $providerAccount);

        // Check if there are any servers using this account
        if ($providerAccount->servers()->exists()) {
            return redirect()
                ->route('provider-accounts.index')
                ->with('error', 'Cannot delete provider account with active servers.');
        }

        $providerAccount->delete();

        return redirect()
            ->route('provider-accounts.index')
            ->with('success', 'Provider account disconnected.');
    }

    public function validate(ProviderAccount $providerAccount): RedirectResponse
    {
        $this->authorize('update', $providerAccount);

        ValidateProviderJob::dispatch($providerAccount);

        return redirect()
            ->route('provider-accounts.index')
            ->with('success', 'Credentials validation started.');
    }
}
