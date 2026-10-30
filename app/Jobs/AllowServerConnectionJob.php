<?php

namespace App\Jobs;

use App\Actions\ServerConnections\AllowServerConnection;
use App\Enums\ServerConnectionStatus;
use App\Events\ServerConnectionsUpdated;
use App\Models\ServerConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AllowServerConnectionJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public ServerConnection $serverConnection,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(AllowServerConnection $action): void
    {
        try {
            $action->execute($this->serverConnection);
            $this->serverConnection->update(['status' => ServerConnectionStatus::Active, 'error_message' => null]);
        } catch (\Throwable $e) {
            Log::error('AllowServerConnectionJob failed', [
                'server_connection_id' => $this->serverConnection->id,
                'message' => $e->getMessage(),
            ]);
            $this->serverConnection->update([
                'status' => ServerConnectionStatus::Failed,
                'error_message' => $this->serverConnection->host->redactSecrets($e->getMessage()),
            ]);
            throw $e;
        } finally {
            event(new ServerConnectionsUpdated($this->serverConnection->host));
        }
    }
}
