<?php

namespace App\Jobs;

use App\Actions\Worker\DestroyWorker;
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
        try {
            $action->execute($this->worker);
            $this->worker->delete();
        } catch (\Throwable $e) {
            Log::error('DestroyWorkerJob failed', [
                'worker_id' => $this->worker->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
