<?php

namespace App\Jobs;

use App\Actions\Worker\ControlWorker;
use App\Enums\WorkerControlAction;
use App\Models\Worker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ControlWorkerJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public Worker $worker,
        public WorkerControlAction $action,
    ) {
        $this->onQueue('ssh');
    }

    public function handle(ControlWorker $action): void
    {
        try {
            $action->execute($this->worker, $this->action);
        } catch (\Throwable $e) {
            Log::error('ControlWorkerJob failed', [
                'worker_id' => $this->worker->id,
                'action' => $this->action->value,
                'message' => $e->getMessage(),
            ]);
            $this->worker->update(['status' => 'failed']);
            throw $e;
        }
    }
}
