<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\SiteStatus;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fans out an uptime check to every site that should be serving traffic.
 * Scheduled periodically; each site's HTTP check runs as its own queued job
 * so one slow site can't delay the rest.
 *
 * A failed site is still checked when it has a finished deployment: a failed
 * deploy leaves the previous release serving, so it's live and must stay
 * monitored. A site that failed provisioning never went live, so it isn't.
 */
class CheckAllSitesUptimeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): void
    {
        Site::query()
            ->whereHas('server', fn (Builder $query) => $query->where('status', ServerStatus::Active))
            ->whereHas('primaryDomain')
            ->where(fn (Builder $query) => $query
                ->whereIn('status', [SiteStatus::Provisioned, SiteStatus::Deployed, SiteStatus::Deploying])
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', SiteStatus::Failed)
                    ->whereHas('deployments', fn (Builder $query) => $query->where('status', DeploymentStatus::Finished))))
            ->get()
            ->each(fn (Site $site) => CheckSiteUptimeJob::dispatch($site));
    }
}
