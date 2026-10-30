<?php

namespace App\Jobs;

use App\Actions\Sites\RemoveSiteFromServerAction;
use App\Data\SiteServerFootprint;
use App\Enums\SiteStatus;
use App\Events\ServerSitesUpdated;
use App\Models\Site;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Clears away whatever a failed site creation left on the server, then
 * creates the site again from its saved settings. The site's DNS record is
 * kept, as it was made before the creation job ran and is still in use.
 */
class RetryCreateSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(SshService $sshService, RemoveSiteFromServerAction $removeFromServer): void
    {
        Log::info("Cleaning up site {$this->site->domain} before retrying its creation");

        $server = $this->site->server;
        $connection = $sshService->connect($server);
        $removeFromServer->execute($connection, $server, SiteServerFootprint::fromSite($this->site));
        $connection->disconnect();

        CreateSiteJob::dispatch($this->site);
    }

    /**
     * Put the site back to failed when the cleanup itself fails, so the user
     * sees why and can retry again.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Cleanup before retrying site {$this->site->id} failed: {$exception->getMessage()}");

        $this->site->update([
            'status' => SiteStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);

        broadcast(new ServerSitesUpdated($this->site->server));
    }
}
