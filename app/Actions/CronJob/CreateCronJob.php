<?php

namespace App\Actions\CronJob;

use App\Jobs\CreateCronJobJob;
use App\Models\CronJob;
use App\Models\Server;
use App\Services\Ssh\SshService;
use RuntimeException;

class CreateCronJob
{
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

            $content = $cronJob->cronLine();
            $tmpPath = '/tmp/'.$filename;

            $connection->upload($content, $tmpPath);

            // Move into /etc/cron.d AND reset ownership/mode — Debian/Ubuntu
            // cron silently skips files in /etc/cron.d that aren't owned by
            // root, so the rename alone is not enough (the file would still
            // be owned by the SSH user).
            $connection->sudo(
                "mv {$tmpPath} {$destPath} && chown root:root {$destPath} && chmod 644 {$destPath}",
                30
            );

            $cronJob->update(['status' => 'active']);
        } finally {
            $connection->disconnect();
        }
    }
}
