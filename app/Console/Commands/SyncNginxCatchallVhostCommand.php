<?php

namespace App\Console\Commands;

use App\Actions\Servers\SyncNginxCatchallVhostAction;
use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Console\Command;

class SyncNginxCatchallVhostCommand extends Command
{
    protected $signature = 'flitops:nginx:sync-catchall
        {--server=* : Specific server ID(s) to target}
        {--all : Apply to every active server}';

    protected $description = 'Push the current catch-all nginx vhost (with the 443 default_server fix) to already-provisioned servers.';

    public function handle(SyncNginxCatchallVhostAction $action): int
    {
        $serverIds = $this->option('server');

        if (empty($serverIds) && ! $this->option('all')) {
            $this->components->error('Pass --server=ID (repeatable) or --all.');

            return self::FAILURE;
        }

        $servers = $this->option('all')
            ? Server::query()->where('status', ServerStatus::Active)->get()
            : Server::query()->whereIn('id', $serverIds)->get();

        if ($servers->isEmpty()) {
            $this->components->warn('No matching servers found.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($servers as $server) {
            try {
                $this->components->task("Server {$server->id} ({$server->name})", function () use ($server, $action) {
                    $action->execute($server);

                    return true;
                });
            } catch (\Throwable $e) {
                $failures++;
                $this->components->error("Server {$server->id}: {$e->getMessage()}");
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
