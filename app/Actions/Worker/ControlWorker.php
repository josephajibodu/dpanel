<?php

namespace App\Actions\Worker;

use App\Enums\WorkerControlAction;
use App\Models\Worker;
use App\Services\Ssh\SshService;
use RuntimeException;

class ControlWorker
{
    public function __construct(
        private SshService $sshService,
    ) {}

    public function execute(Worker $worker, WorkerControlAction $action): void
    {
        $server = $worker->server;

        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $prefix = config('supervisor.file_prefix');
        $programName = "{$prefix}-{$worker->id}";

        $connection = $this->sshService->connect($server);

        try {
            $connection->sudo("supervisorctl {$action->value} {$programName}:*", 30);
            $worker->update(['status' => $action->targetStatus()]);
        } finally {
            $connection->disconnect();
        }
    }
}
