<?php

namespace App\Actions\Worker;

use App\Jobs\UpdateWorkerJob;
use App\Models\Worker;
use App\Services\Ssh\SshService;
use RuntimeException;

class UpdateWorker
{
    public function __construct(
        private SshService $sshService
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Worker $worker, array $input): Worker
    {
        $worker->update([
            'name' => $input['name'],
            'command' => $input['command'],
            'site_id' => $input['site_id'] ?? null,
            'user' => $input['user'],
            'numprocs' => $input['numprocs'],
            'auto_start' => $input['auto_start'] ?? true,
            'auto_restart' => $input['auto_restart'] ?? true,
            'redirect_stderr' => $input['redirect_stderr'] ?? true,
            'stdout_logfile' => $input['stdout_logfile'] ?? null,
        ]);

        UpdateWorkerJob::dispatch($worker);

        return $worker;
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
            $programName = "{$prefix}-{$worker->id}";

            $content = $worker->supervisorConfig($programName);
            $tmpPath = '/tmp/'.$filename;

            $connection->upload($content, $tmpPath);
            $connection->sudo("mv {$tmpPath} {$confPath}/{$filename}", 30);
            $connection->sudo('supervisorctl reread', 30);
            $connection->sudo('supervisorctl update', 30);
        } finally {
            $connection->disconnect();
        }
    }
}
