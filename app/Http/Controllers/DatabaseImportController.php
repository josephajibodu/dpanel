<?php

namespace App\Http\Controllers;

use App\Actions\Backups\ImportDatabaseDump;
use App\Http\Requests\ImportDatabaseDumpRequest;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class DatabaseImportController extends Controller
{
    /**
     * Load an uploaded .sql or .sql.gz dump into the database, replacing
     * its current data.
     */
    public function store(
        ImportDatabaseDumpRequest $request,
        Team $team,
        Server $server,
        ServerDatabase $server_database,
        ImportDatabaseDump $action,
    ): RedirectResponse {
        if ($server->database_type === 'postgresql' && ! $server_database->importOwner()) {
            return redirect()
                ->back()
                ->with('error', "Add a database user with read-write access to {$server_database->name} first. The imported tables are handed to that user.");
        }

        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to import a dump.');
        }

        $action->execute(
            $server_database,
            StorageProvider::findOrFail($request->validated('storage_provider_id')),
            $request->file('dump'),
            $request->user(),
        );

        return redirect()
            ->back()
            ->with('success', 'Import started.');
    }
}
