<?php

namespace App\Http\Controllers;

use App\Actions\Backups\RunSqliteBackup;
use App\Http\Resources\BackupResource;
use App\Http\Resources\BackupScheduleResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Http\Resources\StorageProviderResource;
use App\Models\Backup;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SiteBackupController extends Controller
{
    public function index(Team $team, Server $server, Site $site): Response
    {
        $this->authorize('view', $site);

        $site->load(['server', 'backupSchedule', 'serverDatabase']);

        $backups = $site->backups()
            ->with('user:id,name')
            ->latest()
            ->paginate(20);

        return Inertia::render('sites/backups/index', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
            'usesSqlite' => $site->usesSqlite(),
            'serverDatabase' => $site->serverDatabase?->only(['id', 'name']),
            'schedule' => $site->backupSchedule
                ? new BackupScheduleResource($site->backupSchedule)
                : null,
            'backups' => BackupResource::collection($backups),
            'storageProviders' => StorageProviderResource::collection(
                $team->storageProviders()->where('is_valid', true)->get()
            ),
        ]);
    }

    public function store(Team $team, Server $server, Site $site, RunSqliteBackup $action): RedirectResponse
    {
        $this->authorize('view', $site);

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
        $this->authorize('view', $site);

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
        $this->authorize('view', $site);

        if (! $backup->storage_path) {
            return redirect()->back()->with('error', 'This backup has no file to download.');
        }

        $driver = $storageProviderManager->forAccount($backup->storageProvider);
        $url = $driver->temporaryUrl($backup->storage_path, now()->addMinutes(5));

        return redirect()->away($url);
    }
}
