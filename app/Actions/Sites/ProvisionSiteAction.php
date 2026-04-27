<?php

namespace App\Actions\Sites;

use App\Enums\ProjectType;
use App\Enums\SiteStatus;
use App\Events\ServerSitesUpdated;
use App\Models\Site;
use App\Services\Nginx\SiteNginxSyncService;
use App\Services\SiteProvisioning\SiteProvisionerFactory;
use App\Services\SourceControlService;
use App\Services\Ssh\SshService;
use Illuminate\Support\Facades\Log;

class ProvisionSiteAction
{
    public function __construct(
        private SshService $sshService,
        private SiteNginxSyncService $siteNginxSyncService,
        private SourceControlService $sourceControlService,
        private TriggerDeploymentAction $triggerDeployment,
    ) {}

    public function execute(Site $site): void
    {
        $server = $site->server;

        Log::info("Creating site {$site->domain} on server {$server->name}");

        try {
            $site->update(['status' => SiteStatus::Installing]);
            $this->broadcastServerSitesUpdated($server);

            $connection = $this->sshService->connect($server);

            $provisioner = SiteProvisionerFactory::make(
                $site->project_type ?? ProjectType::Laravel,
                $connection,
                $site,
                $this->siteNginxSyncService,
                $this->sourceControlService,
            );

            $provisioner->provision(
                onStep: fn ($step) => $this->setStepAndBroadcast($site, $step),
            );

            $connection->disconnect();

            $site->update(['status' => SiteStatus::Provisioned, 'provisioning_step' => null]);

            Log::info("Site {$site->domain} created successfully");

            $this->broadcastServerSitesUpdated($server);

            // Provisioning is now infra-only. The first install/build/migrate run
            // happens through the standard deployment path so failures surface in
            // the deployment UI (streaming logs, retry) instead of laravel.log.
            if ($site->repository) {
                $this->triggerDeployment->execute($site, 'auto');
            }
        } catch (\Throwable $e) {
            Log::error("Failed to create site {$site->domain}: {$e->getMessage()}");

            $site->update(['status' => SiteStatus::Failed]);

            $this->broadcastServerSitesUpdated($server);

            throw $e;
        }
    }

    private function setStepAndBroadcast(Site $site, $step): void
    {
        $site->update(['provisioning_step' => $step]);
        $this->broadcastServerSitesUpdated($site->server);
    }

    private function broadcastServerSitesUpdated($server): void
    {
        $server->load(['sites' => fn ($q) => $q->with('latestDeployment')->latest()]);
        broadcast(new ServerSitesUpdated($server));
    }
}
