<?php

namespace App\Actions\CronJob;

use App\Actions\Database\EscapesShell;
use App\Jobs\EnableCronJobJob;
use App\Models\CronJob;
use App\Services\Ssh\SshService;
use App\Services\Ssh\StagesFilesPrivately;
use RuntimeException;

class EnableCronJob
{
    use EscapesShell, StagesFilesPrivately;

    public function __construct(
        private SshService $sshService
    ) {}

    public function enable(CronJob $cronJob): void
    {
        $cronJob->update(['hidden' => false]);
        EnableCronJobJob::dispatch($cronJob);
    }

    public function execute(CronJob $cronJob): void
    {
        $server = $cronJob->server;

        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $connection = $this->sshService->connect($server);

        try {
            $path = config('cron.cron_d_path');
            $prefix = config('cron.file_prefix');
            $filename = "{$prefix}-{$cronJob->id}";
            $destPath = "{$path}/{$filename}";

            $this->installAsRoot($connection, $cronJob->cronLine(), $destPath);
        } finally {
            $connection->disconnect();
        }
    }
}
