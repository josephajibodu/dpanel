<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Nginx\SiteNginxSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSiteNginxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public Site $site) {}

    public function handle(SiteNginxSyncService $sync): void
    {
        $this->site->loadMissing('server', 'domains');
        $sync->sync($this->site);
    }
}
