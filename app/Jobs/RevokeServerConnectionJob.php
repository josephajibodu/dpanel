<?php

namespace App\Jobs;

use App\Actions\ServerConnections\RevokeServerConnection;
use App\Enums\ServerConnectionStatus;
use App\Events\ServerConnectionsUpdated;
use App\Models\ServerConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RevokeServerConnectionJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public ServerConnection $serverConnection,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(RevokeServerConnection $action): void
    {
        $host = $this->serverConnection->host;

        try {
            $action->execute($this->serverConnection);
        } catch (\Throwable $e) {
            Log::error('RevokeServerConnectionJob failed', [
                'server_connection_id' => $this->serverConnection->id,
                'message' => $e->getMessage(),
            ]);
            $this->serverConnection->update([
                'status' => ServerConnectionStatus::Failed,
                'error_message' => $host->redactSecrets($e->getMessage()),
            ]);
            throw $e;
        } finally {
            event(new ServerConnectionsUpdated($host));
        }
    }
}
