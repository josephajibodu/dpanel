<?php

namespace App\Http\Controllers;

use App\Actions\Backups\RestoreDatabaseBackup;
use App\Actions\Backups\RunDatabaseBackup;
use App\Http\Resources\BackupResource;
use App\Http\Resources\BackupScheduleResource;
use App\Http\Resources\ServerDatabaseResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\StorageProviderResource;
use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Team;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BackupController extends Controller
{
    public function index(Team $team, Server $server, ServerDatabase $server_database): Response
    {
        $this->authorize('view', $server);

        $server_database->load('backupSchedule');

        $backups = $server_database->backups()
            ->with('user:id,name')
            ->latest()
            ->paginate(20);

        return Inertia::render('servers/databases/backups/index', [
            'server' => new ServerResource($server),
            'serverDatabase' => new ServerDatabaseResource($server_database),
            'schedule' => $server_database->backupSchedule
                ? new BackupScheduleResource($server_database->backupSchedule)
                : null,
            'backups' => BackupResource::collection($backups),
            'storageProviders' => StorageProviderResource::collection(
                $team->storageProviders()->where('is_valid', true)->get()
            ),
        ]);
    }

    public function store(Team $team, Server $server, ServerDatabase $server_database, RunDatabaseBackup $action): RedirectResponse
    {
        $this->authorize('view', $server);

        $schedule = $server_database->backupSchedule;

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

        $action->trigger($server_database, $schedule->storageProvider, 'manual', auth()->user());

        return redirect()
            ->back()
            ->with('success', 'Backup started.');
    }

    public function destroy(
        Team $team,
        Server $server,
        ServerDatabase $server_database,
        Backup $backup,
        StorageProviderManager $storageProviderManager,
    ): RedirectResponse {
        $this->authorize('view', $server);

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

    public function restore(
        Team $team,
        Server $server,
        ServerDatabase $server_database,
        Backup $backup,
        RestoreDatabaseBackup $action,
    ): RedirectResponse {
        $this->authorize('view', $server);

        if ($backup->status !== 'completed') {
            return redirect()
                ->back()
                ->with('error', 'Only a completed, verified backup can be restored.');
        }

        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to restore a backup.');
        }

        $action->trigger($backup);

        return redirect()
            ->back()
            ->with('success', 'Restore started.');
    }

    public function download(
        Team $team,
        Server $server,
        ServerDatabase $server_database,
        Backup $backup,
        StorageProviderManager $storageProviderManager,
    ): RedirectResponse {
        $this->authorize('view', $server);

        if (! $backup->storage_path) {
            return redirect()->back()->with('error', 'This backup has no file to download.');
        }

        $driver = $storageProviderManager->forAccount($backup->storageProvider);
        $url = $driver->temporaryUrl($backup->storage_path, now()->addMinutes(5));

        return redirect()->away($url);
    }
}
