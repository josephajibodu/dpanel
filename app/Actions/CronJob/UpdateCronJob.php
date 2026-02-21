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

            if ($cronJob->hidden) {
                $connection->sudo("rm -f {$path}/{$filename}", 30);

                return;
            }

            $content = $this->buildCronFileContent($cronJob);
            $tmpPath = '/tmp/'.$filename;

            $connection->upload($content, $tmpPath);
            $connection->sudo("mv {$tmpPath} {$path}/{$filename}", 30);
        } finally {
            $connection->disconnect();
        }
    }

    private function buildCronFileContent(CronJob $cronJob): string
    {
        $line = $cronJob->frequency.' '.$cronJob->user.' '.$cronJob->command."\n";

        return $line;
    }
}
