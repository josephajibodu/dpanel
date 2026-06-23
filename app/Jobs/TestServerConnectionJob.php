<?php

namespace App\Jobs;

use App\Events\ServerConnectionTested;
use App\Models\Server;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TestServerConnectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public Server $server,
    ) {}

    public function handle(SshService $sshService): void
    {
        try {
            $connection = $sshService->connectAsRoot($this->server);
            $connection->disconnect();

            event(new ServerConnectionTested(
                server: $this->server,
                successful: true,
                message: 'Connected successfully.',
            ));
        } catch (\Throwable $e) {
            event(new ServerConnectionTested(
                server: $this->server,
                successful: false,
                message: $e->getMessage(),
            ));
        }
    }
}
