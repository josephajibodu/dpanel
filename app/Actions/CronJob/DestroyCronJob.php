<?php

namespace App\Actions\CronJob;

use App\Jobs\DestroyCronJobJob;
use App\Models\CronJob;
use App\Services\Ssh\SshService;
use RuntimeException;

class DestroyCronJob
{
    public function __construct(
        private SshService $sshService
    ) {}

    public function delete(CronJob $cronJob): void
    {
        DestroyCronJobJob::dispatch($cronJob);
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

            $connection->sudo("rm -f {$path}/{$filename}", 30);
        } finally {
            $connection->disconnect();
        }
    }
}
