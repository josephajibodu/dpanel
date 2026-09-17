<?php

namespace App\Jobs;

use App\Actions\Sites\ProvisionSiteAction;
use App\Enums\SiteStatus;
use App\Events\ServerSitesUpdated;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 0;

    public int $timeout = 300;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(ProvisionSiteAction $action): void
    {
        $action->execute($this->site);
    }

    /**
     * Handle a job failure that occurs outside ProvisionSiteAction's own
     * try/catch (e.g. the job timing out), so the site never gets stuck
     * showing "Installing" forever.
     */
    public function failed(\Throwable $exception): void
    {
        if ($this->site->status === SiteStatus::Failed) {
            return;
        }

        Log::error("CreateSiteJob failed for site {$this->site->id}: {$exception->getMessage()}");

        $this->site->update([
            'status' => SiteStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);

        $server = $this->site->server()->with(['sites' => fn ($q) => $q->with('latestDeployment')->latest()])->first();

        if ($server) {
            broadcast(new ServerSitesUpdated($server));
        }
    }
}
