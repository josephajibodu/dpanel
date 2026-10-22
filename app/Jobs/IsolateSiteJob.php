<?php

namespace App\Jobs;

use App\Actions\Sites\IsolateSiteAction;
use App\Enums\SiteStatus;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Converts an existing site to run as its own Linux user. Not retried
 * automatically: a failed attempt has already been rolled back, and the
 * user can start it again from the site page.
 */
class IsolateSiteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public Site $site,
        public SiteStatus $previousStatus,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(IsolateSiteAction $action): void
    {
        $action->execute($this->site, $this->previousStatus);
    }

    /**
     * The job was stopped before it could finish (a timeout or a worker
     * crash), so the site may need a look on the server.
     */
    public function failed(\Throwable $e): void
    {
        $site = $this->site->fresh();

        if ($site?->status === SiteStatus::Isolating) {
            $site->update([
                'status' => $this->previousStatus,
                'error_message' => 'Isolating this site stopped before it finished. Check the site, then try again.',
            ]);
        }
    }
}
