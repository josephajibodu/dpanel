<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

class ForgetServerHostKeyCommand extends Command
{
    protected $signature = 'flitops:server:forget-host-key
        {server : The ID of the server that was rebuilt or reinstalled}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Forget the recorded SSH host key of a server that was rebuilt, so the next connection records its new key.';

    public function handle(): int
    {
        $server = Server::query()->find($this->argument('server'));

        if (! $server) {
            $this->components->error('No server found with that ID.');

            return self::FAILURE;
        }

        if (! $server->hasPinnedHostKey()) {
            $this->components->info("Server {$server->id} ({$server->name}) has no recorded host key yet. Nothing to reset.");

            return self::SUCCESS;
        }

        $this->components->warn("Only do this if you know {$server->name} ({$server->ip_address}) was rebuilt or reinstalled. Otherwise the changed key may mean someone is intercepting the connection.");
        $this->components->twoColumnDetail('Recorded key', "{$server->host_key_type} {$server->host_key_fingerprint}");

        if (! $this->option('force') && ! $this->components->confirm('Forget this host key?')) {
            return self::FAILURE;
        }

        $server->forgetHostKey();

        $this->components->info("Forgot the host key for server {$server->id}. The next successful connection will record the new one.");

        return self::SUCCESS;
    }
}
