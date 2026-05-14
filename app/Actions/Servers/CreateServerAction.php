<?php

namespace App\Actions\Servers;

use App\Data\ServerData;
use App\Enums\ServerStatus;
use App\Jobs\ProvisionServerJob;
use App\Models\ProviderRegion;
use App\Models\ProviderSize;
use App\Models\Server;
use App\Models\User;
use App\Services\Providers\ProviderManager;
use App\Services\Ssh\KeyGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateServerAction
{
    public function __construct(
        private KeyGenerator $keyGenerator,
        private ProviderManager $providers,
    ) {}

    public function execute(User $user, ServerData $data): Server
    {
        // Validate provider account belongs to user
        $providerAccount = $user->providerAccounts()
            ->findOrFail($data->providerAccountId);

        // Validate provider credentials
        $provider = $this->providers->forAccount($providerAccount);
        if (! $provider->validateCredentials()) {
            throw ValidationException::withMessages([
                'provider_account_id' => 'Provider credentials are invalid.',
            ]);
        }

        // Generate SSH keypair for this server
        $keyPair = $this->keyGenerator->generate();

        // Create SSH key at provider
        $appSlug = \Illuminate\Support\Str::slug((string) config('app.name'), '-');

        $providerKeyId = $provider->createSshKey(
            name: $appSlug.'-'.$data->name.'-'.Str::random(8),
            publicKey: $keyPair->publicKey,
        );

        // Look up the provider region and size records
        $providerRegion = ProviderRegion::where('provider', $providerAccount->provider)
            ->where('code', $data->region)
            ->first();

        $providerSize = ProviderSize::where('provider', $providerAccount->provider)
            ->where('code', $data->size)
            ->first();

        // Create server record — if this fails, clean up the provider SSH key
        // so it doesn't sit orphaned on the provider account.
        try {
            $server = DB::transaction(function () use (
                $user,
                $data,
                $providerAccount,
                $keyPair,
                $providerKeyId,
                $providerRegion,
                $providerSize,
            ) {
                $server = $user->servers()->create([
                    'provider_account_id' => $providerAccount->id,
                    'provider' => $providerAccount->provider,
                    'name' => $data->name,
                    'size' => $data->size,
                    'region' => $data->region,
                    'provider_region_id' => $providerRegion?->id,
                    'provider_size_id' => $providerSize?->id,
                    'php_version' => $data->phpVersion,
                    'database_type' => $data->databaseType,
                    'status' => ServerStatus::Pending,
                    'meta' => [
                        'provider_ssh_key_id' => $providerKeyId,
                    ],
                ]);

                $server->credentials()->createMany([
                    [
                        'type' => 'private_key',
                        'value' => $keyPair->privateKey,
                    ],
                    [
                        'type' => 'public_key',
                        'value' => $keyPair->publicKey,
                    ],
                    [
                        'type' => 'sudo_password',
                        'value' => Str::random(32),
                    ],
                    [
                        'type' => 'database_password',
                        'value' => Str::random(32),
                    ],
                ]);

                return $server;
            });
        } catch (\Throwable $e) {
            rescue(fn () => $provider->deleteSshKey($providerKeyId));
            throw $e;
        }

        // Dispatch provisioning job
        ProvisionServerJob::dispatch($server);

        return $server;
    }
}
