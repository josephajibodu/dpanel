<?php

namespace App\Http\Controllers;

use App\Enums\StorageProviderType;
use App\Http\Requests\StoreStorageProviderRequest;
use App\Http\Resources\StorageProviderResource;
use App\Jobs\ValidateStorageProviderJob;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StorageProviderController extends Controller
{
    public function index(Team $team): Response
    {
        $storageProviders = $team->storageProviders()->latest()->get();

        return Inertia::render('storage-providers/index', [
            'storageProviders' => StorageProviderResource::collection($storageProviders),
            'types' => collect(StorageProviderType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'fields' => $type->credentialFields(),
            ]),
        ]);
    }

    public function store(Team $team, StoreStorageProviderRequest $request, StorageProviderManager $storageProviderManager): RedirectResponse
    {
        $validated = $request->validated();
        $credentials = $request->credentials();

        $driver = $storageProviderManager->driver($validated['type']);
        $driver->setCredentials($credentials);

        $isValid = $driver->validateCredentials();

        $team->storageProviders()->create([
            'user_id' => auth()->id(),
            'type' => $validated['type'],
            'name' => $validated['name'],
            'credentials' => $credentials,
            'is_valid' => $isValid,
            'validated_at' => $isValid ? now() : null,
        ]);

        if (! $isValid) {
            return redirect()
                ->route('storage-providers.index', $team)
                ->with('error', 'Storage provider connected but credentials could not be validated. Please check your access key, secret, and bucket name.');
        }

        return redirect()
            ->route('storage-providers.index', $team)
            ->with('success', 'Storage provider connected successfully.');
    }

    public function destroy(Team $team, StorageProvider $storageProvider): RedirectResponse
    {
        $this->authorize('delete', $storageProvider);

        if ($storageProvider->backups()->exists() || $storageProvider->backupSchedules()->exists()) {
            return redirect()
                ->route('storage-providers.index', $team)
                ->with('error', 'Cannot disconnect a storage provider that backups still reference.');
        }

        $storageProvider->delete();

        return redirect()
            ->route('storage-providers.index', $team)
            ->with('success', 'Storage provider disconnected.');
    }

    public function validate(Team $team, StorageProvider $storageProvider): RedirectResponse
    {
        $this->authorize('update', $storageProvider);

        ValidateStorageProviderJob::dispatch($storageProvider);

        return redirect()
            ->route('storage-providers.index', $team)
            ->with('success', 'Credentials validation started.');
    }
}
