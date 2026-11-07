<?php

namespace App\Http\Controllers;

use App\Events\BackupStatusChanged;
use App\Http\Resources\BackupResource;
use App\Http\Resources\BackupScheduleResource;
use App\Http\Resources\DatabaseConnectionResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Http\Resources\StorageProviderResource;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

class SiteDatabaseController extends Controller
{
    /**
     * The database a site uses and its backups, whether that is a server
     * database (possibly on another server) or the site's own SQLite file.
     */
    public function show(Team $team, Server $server, Site $site): Response
    {
        $this->authorize('view', $site);

        $site->load(['server', 'backupSchedule', 'serverDatabase.server', 'serverDatabase.backupSchedule']);
        $database = $site->serverDatabase;

        $schedule = $database ? $database->backupSchedule : $site->backupSchedule;
        $backups = ($database ? $database->backups() : $site->backups())
            ->with('user:id,name')
            ->latest()
            ->paginate(20);

        $backupStatusChanged = new BackupStatusChanged($database ? $database->server : $server);

        return Inertia::render('sites/database/show', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
            'usesSqlite' => $site->usesSqlite(),
            'sqlitePath' => $site->usesSqlite() ? $site->sqliteDatabasePath() : null,
            'databaseConnection' => $database ? DatabaseConnectionResource::make($database)->resolve() : null,
            'schedule' => Inertia::live($schedule ? new BackupScheduleResource($schedule) : null, on: $backupStatusChanged),
            'backups' => Inertia::live(BackupResource::collection($backups), on: $backupStatusChanged),
            'storageProviders' => StorageProviderResource::collection(
                $team->storageProviders()->where('is_valid', true)->get()
            ),
        ]);
    }
}
