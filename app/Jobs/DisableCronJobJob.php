<?php

namespace App\Jobs;

use App\Actions\CronJob\DisableCronJob;
use App\Events\ServerProcessesUpdated;
use App\Models\CronJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DisableCronJobJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public CronJob $cronJob
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(DisableCronJob $action): void
    {
        try {
            $action->execute($this->cronJob);
        } catch (\Throwable $e) {
            Log::error('DisableCronJobJob failed', [
                'cron_job_id' => $this->cronJob->id,
                'message' => $e->getMessage(),
            ]);
            $this->cronJob->update(['status' => 'failed']);
            throw $e;
        } finally {
            event(new ServerProcessesUpdated($this->cronJob->server_id));
        }
    }
}
