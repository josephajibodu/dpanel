<?php

namespace App\Jobs;

use App\Actions\Worker\UpdateWorker;
use App\Events\ServerProcessesUpdated;
use App\Models\Worker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateWorkerJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public Worker $worker
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(UpdateWorker $action): void
    {
        try {
            $action->execute($this->worker);
        } catch (\Throwable $e) {
            Log::error('UpdateWorkerJob failed', [
                'worker_id' => $this->worker->id,
                'message' => $e->getMessage(),
            ]);
            $this->worker->update(['status' => 'failed']);
            throw $e;
        } finally {
            event(new ServerProcessesUpdated($this->worker->server_id));
        }
    }
}
