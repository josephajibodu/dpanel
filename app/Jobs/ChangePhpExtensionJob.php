<?php

namespace App\Jobs;

use App\Actions\ServerPhp\ChangePhpExtension;
use App\Enums\PhpExtension;
use App\Events\ServerPhpUpdated;
use App\Models\Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ChangePhpExtensionJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public Service $phpService,
        public PhpExtension $extension,
        public bool $install,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(ChangePhpExtension $action): void
    {
        $server = $this->phpService->server;
        $version = $this->phpService->installed_version ?? $this->phpService->version;

        $this->updateTypeData(['extensions_sync_status' => 'syncing']);
        broadcast(new ServerPhpUpdated($server));

        try {
            $installed = $action->execute($server, $version, $this->extension, $this->install);
            $this->updateTypeData([
                'extensions' => $installed,
                'extensions_sync_status' => 'synced',
                'extensions_sync_error' => null,
                'extensions_pending' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('ChangePhpExtensionJob failed', [
                'service_id' => $this->phpService->id,
                'extension' => $this->extension->value,
                'install' => $this->install,
                'message' => $e->getMessage(),
            ]);
            $this->updateTypeData([
                'extensions_sync_status' => 'failed',
                'extensions_sync_error' => $e->getMessage(),
                'extensions_pending' => null,
            ]);
            throw $e;
        } finally {
            broadcast(new ServerPhpUpdated($server));
        }
    }

    /** @param array<string, mixed> $data */
    private function updateTypeData(array $data): void
    {
        $this->phpService->refresh();
        $this->phpService->update([
            'type_data' => array_merge($this->phpService->type_data ?? [], $data),
        ]);
    }
}
