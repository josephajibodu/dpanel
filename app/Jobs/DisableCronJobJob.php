<?php

namespace App\Jobs;

use App\Actions\CronJob\DisableCronJob;
use App\Models\CronJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        $action->execute($this->cronJob);
    }
}
