<?php

namespace App\Actions\CronJob;

use App\Jobs\UpdateCronJobJob;
use App\Models\CronJob;
use App\Services\Ssh\SshService;
use RuntimeException;

class UpdateCronJob
{
    public function __construct(
        private SshService $sshService
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(CronJob $cronJob, array $input): CronJob
    {
        $cronJob->update([
            'command' => $input['command'],
            'site_id' => $input['site_id'] ?? null,
            'user' => $input['user'],
            'frequency' => $input['frequency'],
        ]);

        UpdateCronJobJob::dispatch($cronJob);

        return $cronJob;
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

            if ($cronJob->hidden) {
                $connection->sudo("rm -f {$destPath}", 30);

                return;
            }

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
