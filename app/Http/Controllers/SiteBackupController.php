<?php

namespace App\Http\Controllers;

use App\Actions\Backups\RunSqliteBackup;
use App\Enums\PlanFeature;
use App\Models\Backup;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Http\RedirectResponse;

class SiteBackupController extends Controller
{
    /**
     * The site's backups now live on its Database page. This keeps links in
     * backup emails that were already sent working.
     */
    public function index(Team $team, Server $server, Site $site): RedirectResponse
    {
        return to_route('servers.sites.database.show', [$team, $server, $site]);
    }

    public function store(Team $team, Server $server, Site $site, RunSqliteBackup $action): RedirectResponse
    {
        $this->authorize('runBackups', $site);
        $this->ensurePlanIncludes($team->plan(), PlanFeature::DatabaseBackups);

        if (! $site->usesSqlite()) {
            return redirect()
                ->back()
                ->with('error', 'Only sites using SQLite can be backed up here.');
        }

        $schedule = $site->backupSchedule;

        if (! $schedule) {
            return redirect()
                ->back()
                ->with('error', 'Configure a backup schedule and storage provider first.');
        }

        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to run a backup.');
        }

        $action->trigger($site, $schedule->storageProvider, 'manual', auth()->user());

        return redirect()
            ->back()
            ->with('success', 'Backup started.');
    }

    public function destroy(
        Team $team,
        Server $server,
        Site $site,
        Backup $backup,
        StorageProviderManager $storageProviderManager,
    ): RedirectResponse {
        $this->authorize('deleteBackups', $site);

        if ($backup->storage_path) {
            try {
                $storageProviderManager->forAccount($backup->storageProvider)->delete($backup->storage_path);
            } catch (\Throwable) {
                // Best effort — still remove the row so it stops cluttering the list.
            }
        }

        $backup->delete();

        return redirect()
            ->back()
            ->with('success', 'Backup deleted.');
    }

    public function download(
        Team $team,
        Server $server,
        Site $site,
        Backup $backup,
        StorageProviderManager $storageProviderManager,
    ): RedirectResponse {
        $this->authorize('viewSecrets', $site);

        if (! $backup->storage_path) {
            return redirect()->back()->with('error', 'This backup has no file to download.');
        }

        $driver = $storageProviderManager->forAccount($backup->storageProvider);
        $url = $driver->temporaryUrl($backup->storage_path, now()->addMinutes(5));

        return redirect()->away($url);
    }
}
