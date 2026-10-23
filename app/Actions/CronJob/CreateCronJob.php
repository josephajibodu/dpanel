<?php

namespace App\Actions\CronJob;

use App\Actions\Database\EscapesShell;
use App\Jobs\CreateCronJobJob;
use App\Models\CronJob;
use App\Models\Server;
use App\Services\Ssh\SshService;
use App\Services\Ssh\StagesFilesPrivately;
use RuntimeException;

class CreateCronJob
{
    use EscapesShell, StagesFilesPrivately;

    public function __construct(
        private SshService $sshService
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Server $server, array $input): CronJob
    {
        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $cronJob = $server->cronJobs()->create([
            'command' => $input['command'],
            'site_id' => $input['site_id'] ?? null,
            'user' => $input['user'],
            'frequency' => $input['frequency'],
            'hidden' => false,
            'status' => 'pending',
        ]);

        CreateCronJobJob::dispatch($cronJob);

        return $cronJob;
    }

    public function execute(CronJob $cronJob): void
    {
        $server = $cronJob->server;

        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        if ($cronJob->hidden) {
            return;
        }

        $connection = $this->sshService->connect($server);

        try {
            $path = config('cron.cron_d_path');
            $prefix = config('cron.file_prefix');
            $filename = "{$prefix}-{$cronJob->id}";
            $destPath = "{$path}/{$filename}";

            $this->installAsRoot($connection, $cronJob->cronLine(), $destPath);

            $cronJob->update(['status' => 'active']);
        } finally {
            $connection->disconnect();
        }
    }
}
