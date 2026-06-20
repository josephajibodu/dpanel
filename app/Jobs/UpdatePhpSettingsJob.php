<?php

namespace App\Jobs;

use App\Actions\ServerPhp\UpdatePhpSettings;
use App\Events\ServerPhpUpdated;
use App\Models\Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdatePhpSettingsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public Service $phpService,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(UpdatePhpSettings $action): void
    {
        $server = $this->phpService->server;

        $this->updateTypeData(['settings_sync_status' => 'syncing']);
        broadcast(new ServerPhpUpdated($server));

        try {
            $settings = $this->phpService->fresh()->type_data['settings'] ?? [];
            $action->execute($server, $settings);
            $this->updateTypeData([
                'settings_sync_status' => 'synced',
                'settings_sync_error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('UpdatePhpSettingsJob failed', [
                'service_id' => $this->phpService->id,
                'message' => $e->getMessage(),
            ]);
            $this->updateTypeData([
                'settings_sync_status' => 'failed',
                'settings_sync_error' => $e->getMessage(),
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
