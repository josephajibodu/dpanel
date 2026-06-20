<?php

namespace App\Actions\Worker;

use App\Events\ServerProcessesUpdated;
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
        $worker->update(['status' => 'deleting']);
        event(new ServerProcessesUpdated($worker->server_id));
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

            $connection->sudo("rm -f {$confPath}/{$filename}", 30);
            $connection->sudo('supervisorctl reread', 30);
            $connection->sudo('supervisorctl update', 30);
        } finally {
            $connection->disconnect();
        }
    }
}
