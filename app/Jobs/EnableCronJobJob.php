<?php

namespace App\Jobs;

use App\Actions\CronJob\EnableCronJob;
use App\Models\CronJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnableCronJobJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public CronJob $cronJob
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(EnableCronJob $action): void
    {
        try {
            $action->execute($this->cronJob);
            $this->cronJob->update(['status' => 'active']);
        } catch (\Throwable $e) {
            Log::error('EnableCronJobJob failed', [
                'cron_job_id' => $this->cronJob->id,
                'message' => $e->getMessage(),
            ]);
            $this->cronJob->update(['status' => 'failed']);
            throw $e;
        }
    }
}
