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

            $content = $this->buildSupervisorConfig($worker, $programName);
            $tmpPath = '/tmp/'.$filename;

            $connection->upload($content, $tmpPath);
            $connection->sudo(
                "mv {$tmpPath} {$confPath}/{$filename} && supervisorctl reread && supervisorctl update",
                60
            );
        } finally {
            $connection->disconnect();
        }
    }

    private function buildSupervisorConfig(Worker $worker, string $programName): string
    {
        $lines = [
            '[program:'.$programName.']',
            'command='.$worker->command,
            'user='.$worker->user,
            'numprocs='.$worker->numprocs,
            'autostart='.($worker->auto_start ? 'true' : 'false'),
            'autorestart='.($worker->auto_restart ? 'true' : 'false'),
            'redirect_stderr='.($worker->redirect_stderr ? 'true' : 'false'),
        ];

        if ($worker->numprocs > 1) {
            $lines[] = 'process_name=%(program_name)s_%(process_num)02d';
        }

        if ($worker->stdout_logfile) {
            $lines[] = 'stdout_logfile='.$worker->stdout_logfile;
        }

        return implode("\n", $lines)."\n";
    }
}
