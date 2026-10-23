<?php

namespace App\Actions\CronJob;

use App\Actions\Database\EscapesShell;
use App\Jobs\UpdateCronJobJob;
use App\Models\CronJob;
use App\Services\Ssh\SshService;
use App\Services\Ssh\StagesFilesPrivately;
use RuntimeException;

class UpdateCronJob
{
    use EscapesShell, StagesFilesPrivately;

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

            $this->installAsRoot($connection, $cronJob->cronLine(), $destPath);
        } finally {
            $connection->disconnect();
        }
    }
}
