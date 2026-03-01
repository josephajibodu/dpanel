<?php

namespace App\Jobs;

use App\Actions\Sites\RunSiteCommand;
use App\Enums\SiteCommandRunStatus;
use App\Models\SiteCommandRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunSiteCommandJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 330;

    public function __construct(
        public SiteCommandRun $run
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(RunSiteCommand $action): void
    {
        try {
            $action->execute($this->run);
        } catch (\Throwable $e) {
            Log::error('RunSiteCommandJob failed', [
                'site_command_run_id' => $this->run->id,
                'message' => $e->getMessage(),
            ]);
            $this->run->update([
                'status' => SiteCommandRunStatus::Failed,
                'output' => ($this->run->output ?? '')."\n\nError: ".$e->getMessage(),
                'exit_code' => $e instanceof \App\Exceptions\SshCommandException ? $e->exitCode : 1,
                'finished_at' => now(),
            ]);
            throw $e;
        }
    }
}
