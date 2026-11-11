<?php

namespace App\Jobs;

use App\Enums\NginxFileSection;
use App\Events\SiteNginxUpdated;
use App\Models\Site;
use App\Notifications\SiteWebServerSyncFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncSiteNginxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public Site $site) {}

    public function handle(): void
    {
        $this->site->loadMissing('server', 'domains');
        $this->site->server->webServer()->syncSite($this->site);
    }

    /**
     * The change that asked for this sync was already saved and reported as
     * done, so a sync that fails for good has to say so: the site's main
     * configs are marked as not applied, with the reason, and the server's
     * owner is emailed. The next sync that works clears the mark.
     */
    public function failed(Throwable $exception): void
    {
        $server = $this->site->server;
        $error = $server->redactSecrets($exception->getMessage());

        $this->site->nginxFiles()
            ->where('section', NginxFileSection::Main->value)
            ->update(['sync_status' => 'failed', 'sync_error' => $error]);

        broadcast(new SiteNginxUpdated($this->site));

        ($server->team?->owner ?? $server->user)?->notify(new SiteWebServerSyncFailed($this->site, $error));
    }
}
