<?php

namespace App\Actions\Servers;

use App\Data\ServerData;
use App\Enums\ServerStatus;
use App\Jobs\ProvisionServerJob;
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
        $providerKeyId = $provider->createSshKey(
            name: 'serverforge-'.$data->name.'-'.Str::random(8),
            publicKey: $keyPair->publicKey,
        );

        // Create server record
        $server = DB::transaction(function () use (
            $user,
            $data,
            $providerAccount,
            $keyPair,
            $providerKeyId,
        ) {
            $server = $user->servers()->create([
                'provider_account_id' => $providerAccount->id,
                'provider' => $providerAccount->provider,
                'name' => $data->name,
                'size' => $data->size,
                'region' => $data->region,
                'php_version' => $data->phpVersion,
                'database_type' => $data->databaseType,
                'status' => ServerStatus::Pending,
                'meta' => [
                    'provider_ssh_key_id' => $providerKeyId,
                ],
            ]);

            // Store credentials
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

        // Dispatch provisioning job
        ProvisionServerJob::dispatch($server);

        return $server;
    }
}
