<?php

namespace App\Actions\CronJob;

use App\Jobs\DisableCronJobJob;
use App\Models\CronJob;
use App\Services\Ssh\SshService;

class DisableCronJob
{
    public function __construct(
        private SshService $sshService
    ) {}

    public function disable(CronJob $cronJob): void
    {
        $cronJob->update(['hidden' => true]);
        DisableCronJobJob::dispatch($cronJob);
    }

    public function execute(CronJob $cronJob): void
    {
        $server = $cronJob->server;

        if (! $server->isReady()) {
            return;
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
