<?php

namespace App\Jobs;

use App\Events\ServerPhpUpdated;
use App\Models\Service;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SetDefaultPhpVersionJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public Service $phpService,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(SshService $sshService): void
    {
        $server = $this->phpService->server;
        $version = $this->phpService->version;

        try {
            $connection = $sshService->connect($server);
            try {
                $connection->sudo("update-alternatives --set php /usr/bin/php{$version} 2>/dev/null || true", 10);
            } finally {
                $connection->disconnect();
            }
        } catch (\Throwable $e) {
            Log::error('SetDefaultPhpVersionJob failed', [
                'service_id' => $this->phpService->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            broadcast(new ServerPhpUpdated($server));
        }
    }
}
