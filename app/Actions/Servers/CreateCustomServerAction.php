<?php

namespace App\Actions\Servers;

use App\Data\CustomServerData;
use App\Enums\Provider;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Ssh\KeyGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateCustomServerAction
{
    public function __construct(
        private KeyGenerator $keyGenerator,
    ) {}

    public function execute(User $user, Team $team, CustomServerData $data): Server
    {
        $keypair = $this->keyGenerator->generate();

        return DB::transaction(function () use ($user, $team, $data, $keypair) {
            $server = $team->servers()->create([
                'user_id' => $user->id,
                'provider' => Provider::Custom,
                'name' => $data->name,
                'ip_address' => $data->ipAddress,
                'ssh_port' => $data->sshPort,
                'php_version' => $data->phpVersion,
                'database_type' => $data->databaseType,
                'status' => ServerStatus::Pending,
            ]);

            $server->credentials()->createMany([
                ['type' => 'private_key', 'value' => $keypair->privateKey],
                ['type' => 'public_key', 'value' => $keypair->publicKey],
                ['type' => 'sudo_password', 'value' => Str::random(32)],
                ['type' => 'database_password', 'value' => Str::random(32)],
            ]);

            return $server;
        });
    }
}
