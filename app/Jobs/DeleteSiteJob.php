<?php

namespace App\Jobs;

use App\Actions\Sites\CleanupSiteExternalResourcesAction;
use App\Actions\Sites\RemoveSiteFromServerAction;
use App\Data\SiteServerFootprint;
use App\Events\ServerSitesUpdated;
use App\Models\CronJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\Worker;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    protected string $domain;

    protected int $serverId;

    protected int $siteId;

    /**
     * @var array<int, string>
     */
    protected array $cloudflareDnsRecordIds;

    /**
     * Worked out now, since the site row may be gone by the time the job runs.
     */
    protected SiteServerFootprint $footprint;

    public function __construct(Site $site)
    {
        $site->loadMissing('domains');
        $this->domain = $site->domain;
        $this->serverId = $site->server_id;
        $this->siteId = $site->id;
        $this->cloudflareDnsRecordIds = $site->domains
            ->pluck('cloudflare_dns_record_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $this->footprint = SiteServerFootprint::fromSite($site);
    }

    public function handle(
        SshService $sshService,
        CleanupSiteExternalResourcesAction $cleanupAction,
    ): void {
        Log::info("Deleting site {$this->domain}");

        $site = Site::find($this->siteId);
        $server = Server::find($this->serverId);

        $cleanupAction->execute(
            cloudflareDnsRecordIds: $this->cloudflareDnsRecordIds,
            server: $server,
            sourceControlAccount: $site?->sourceControlAccount,
            domain: $this->domain,
        );

        // Fetched before $site->delete(), which nulls site_id on these via the
        // FK's nullOnDelete() the moment it runs — after that they're no
        // longer findable by site_id at all.
        $workers = Worker::where('site_id', $this->siteId)->get();
        $cronJobs = CronJob::where('site_id', $this->siteId)->get();

        if (! $server) {
            Log::warning("Server {$this->serverId} not found, skipping site deletion");

            if ($site) {
                $site->delete();
            }

            return;
        }

        if ($site) {
            $site->delete();
        }

        // Each dispatched job removes its own remote supervisor/cron.d file and
        // deletes its row once that succeeds — otherwise these would survive
        // with site_id nulled, still running against a site directory that's
        // about to be rm -rf'd below.
        foreach ($workers as $worker) {
            DestroyWorkerJob::dispatch($worker);
        }

        foreach ($cronJobs as $cronJob) {
            DestroyCronJobJob::dispatch($cronJob);
        }

        try {
            $connection = $sshService->connect($server);
            app(RemoveSiteFromServerAction::class)->execute($connection, $server, $this->footprint);
            $connection->disconnect();

            Log::info("Site {$this->domain} deleted successfully");

        } catch (\Throwable $e) {
            Log::error("Failed to delete site {$this->domain} from server: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        } finally {
            event(new ServerSitesUpdated($server));
        }
    }
}
