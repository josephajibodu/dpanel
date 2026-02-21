<?php

namespace App\Actions\Worker;

use App\Jobs\DestroyWorkerJob;
use App\Models\Worker;
use App\Services\Ssh\SshService;
use RuntimeException;

class DestroyWorker
{
    public function __construct(
        private SshService $sshService
    ) {}

    public function delete(Worker $worker): void
    {
        DestroyWorkerJob::dispatch($worker);
    }

    public function execute(Worker $worker): void
    {
        $server = $worker->server;

        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $connection = $this->sshService->connect($server);

        try {
            $confPath = config('supervisor.conf_path');
            $prefix = config('supervisor.file_prefix');
            $filename = "{$prefix}-{$worker->id}.conf";

            $connection->sudo(
                "rm -f {$confPath}/{$filename} && supervisorctl reread && supervisorctl update",
                60
            );
        } finally {
            $connection->disconnect();
        }
    }
}
