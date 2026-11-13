<?php

namespace App\Actions\Sites;

use App\Enums\OctaneServer;
use App\Enums\PlanFeature;
use App\Enums\ProjectType;
use App\Enums\SiteStatus;
use App\Jobs\SyncSiteOctaneJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Switches Laravel Octane on or off for a site. Switching on reserves a
 * port on the server and adds the process that will run Octane; the server
 * itself is changed in the background by {@see SyncSiteOctaneJob}, which
 * switches the setting back off if Octane can't be started.
 */
class SetOctaneAction
{
    /**
     * @throws RuntimeException when Octane can't be switched on, with a message fit to show the user.
     */
    public function execute(Site $site, ?OctaneServer $octaneServer): void
    {
        if ($octaneServer === null) {
            if ($site->octane_server === null && ! $this->hasLeftovers($site)) {
                return;
            }

            $site->update(['octane_server' => null]);
            SyncSiteOctaneJob::dispatch($site);

            return;
        }

        if ($octaneServer === $site->octane_server) {
            return;
        }

        $server = $site->server;

        if (! $server->team?->planIncludes(PlanFeature::Octane)) {
            throw new RuntimeException(PlanFeature::Octane->upgradeMessage());
        }

        if ($site->project_type !== ProjectType::Laravel) {
            throw new RuntimeException('Octane is only available for Laravel sites.');
        }

        if (! $server->isReady()) {
            throw new RuntimeException('The server must be connected before Octane can be switched on.');
        }

        if ($site->status !== SiteStatus::Deployed) {
            throw new RuntimeException('Deploy the site once before switching Octane on.');
        }

        DB::transaction(function () use ($site, $server, $octaneServer): void {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();

            $site->update([
                'octane_server' => $octaneServer,
                'octane_port' => $site->octane_port ?? $this->freePort($server),
            ]);

            if (! $site->octaneWorker()->exists()) {
                $server->workers()->create([
                    'site_id' => $site->id,
                    'name' => 'Octane',
                    'command' => $site->octaneCommand(),
                    'user' => $site->systemUser(),
                    'numprocs' => 1,
                    'auto_start' => true,
                    'auto_restart' => true,
                    'redirect_stderr' => true,
                    'restart_on_deploy' => true,
                    'runs_octane' => true,
                    'status' => 'pending',
                ]);
            }
        });

        SyncSiteOctaneJob::dispatch($site);
    }

    /**
     * Whether Octane is off but its port or process is still there, because
     * the server stopped answering while Octane was being switched off.
     */
    private function hasLeftovers(Site $site): bool
    {
        return $site->octane_port !== null || $site->octaneWorker()->exists();
    }

    /**
     * The lowest port in the Octane range that no other site on the server
     * has taken.
     */
    private function freePort(Server $server): int
    {
        $taken = $server->sites()->whereNotNull('octane_port')->pluck('octane_port')->map(fn ($port): int => (int) $port)->all();

        for ($port = (int) config('server.octane_ports.first'); $port <= (int) config('server.octane_ports.last'); $port++) {
            if (! in_array($port, $taken, true)) {
                return $port;
            }
        }

        throw new RuntimeException('This server has no free port left for another Octane site.');
    }
}
