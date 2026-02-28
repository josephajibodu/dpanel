<?php

namespace App\Jobs;

use App\Actions\ServerPhp\InstallPhpVersion;
use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InstallPhpVersionJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public Service $service
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(InstallPhpVersion $action): void
    {
        $server = $this->service->server;
        $version = $this->service->version;

        if ($version === null || $version === '') {
            Log::error('InstallPhpVersionJob: service has no version', ['service_id' => $this->service->id]);
            $this->service->update(['status' => ServiceStatus::Failed]);

            return;
        }

        try {
            $action->execute($server, $version);
            $this->service->update([
                'installed_version' => $version,
                'unit' => "php{$version}-fpm",
                'status' => ServiceStatus::Active,
            ]);
        } catch (\Throwable $e) {
            Log::error('InstallPhpVersionJob failed', [
                'service_id' => $this->service->id,
                'message' => $e->getMessage(),
            ]);
            $this->service->update(['status' => ServiceStatus::Failed]);
            throw $e;
        }
    }
}
