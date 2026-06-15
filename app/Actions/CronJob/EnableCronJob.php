<?php

namespace App\Actions\CronJob;

use App\Jobs\EnableCronJobJob;
use App\Models\CronJob;
use App\Services\Ssh\SshService;
use RuntimeException;

class EnableCronJob
{
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

            $content = $cronJob->cronLine();
            $tmpPath = '/tmp/'.$filename;

            $connection->upload($content, $tmpPath);
            $connection->sudo(
                "mv {$tmpPath} {$destPath} && chown root:root {$destPath} && chmod 644 {$destPath}",
                30
            );
        } finally {
            $connection->disconnect();
        }
    }
}
