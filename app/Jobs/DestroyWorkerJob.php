<?php

namespace App\Jobs;

use App\Actions\Worker\DestroyWorker;
use App\Events\ServerProcessesUpdated;
use App\Models\Worker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DestroyWorkerJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public Worker $worker
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(DestroyWorker $action): void
    {
        // Capture before delete so the event still fires correctly afterwards.
        $serverId = $this->worker->server_id;

        try {
            $action->execute($this->worker);
            $this->worker->delete();
        } catch (\Throwable $e) {
            Log::error('DestroyWorkerJob failed', [
                'worker_id' => $this->worker->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            event(new ServerProcessesUpdated($serverId));
        }
    }
}
