<?php

namespace App\Jobs;

use App\Actions\CronJob\DestroyCronJob;
use App\Models\CronJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DestroyCronJobJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public CronJob $cronJob
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(DestroyCronJob $action): void
    {
        try {
            $action->execute($this->cronJob);
            $this->cronJob->delete();
        } catch (\Throwable $e) {
            Log::error('DestroyCronJobJob failed', [
                'cron_job_id' => $this->cronJob->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
