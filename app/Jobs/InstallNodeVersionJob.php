<?php

namespace App\Jobs;

use App\Actions\ServerRuntime\InstallNodeVersion;
use App\Enums\NodeVersion;
use App\Enums\ServiceStatus;
use App\Events\ServerRuntimeUpdated;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InstallNodeVersionJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public Server $server,
        public NodeVersion $version,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(InstallNodeVersion $action): void
    {
        $this->server->update(['node_status' => ServiceStatus::Installing]);
        broadcast(new ServerRuntimeUpdated($this->server));

        try {
            $action->execute($this->server, $this->version);
            $this->server->update([
                'node_version' => $this->version,
                'node_status' => ServiceStatus::Active,
            ]);
        } catch (\Throwable $e) {
            Log::error('InstallNodeVersionJob failed', [
                'server_id' => $this->server->id,
                'version' => $this->version->value,
                'message' => $e->getMessage(),
            ]);
            $this->server->update(['node_status' => ServiceStatus::Failed]);
            throw $e;
        } finally {
            broadcast(new ServerRuntimeUpdated($this->server));
        }
    }
}
