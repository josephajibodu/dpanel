<?php

namespace App\Jobs;

use App\Actions\Worker\CreateWorker;
use App\Models\Worker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateWorkerJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public Worker $worker
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(CreateWorker $action): void
    {
        try {
            $action->execute($this->worker);
        } catch (\Throwable $e) {
            Log::error('CreateWorkerJob failed', [
                'worker_id' => $this->worker->id,
                'message' => $e->getMessage(),
            ]);
            $this->worker->update(['status' => 'failed']);
            throw $e;
        }
    }
}
