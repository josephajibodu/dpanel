<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBackupScheduleRequest;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class BackupScheduleController extends Controller
{
    public function update(
        Team $team,
        Server $server,
        ServerDatabase $server_database,
        UpdateBackupScheduleRequest $request,
    ): RedirectResponse {
        $this->authorize('view', $server);

        $validated = $request->validated();

        $schedule = $server_database->backupSchedule()->updateOrCreate([], [
            'storage_provider_id' => $validated['storage_provider_id'],
            'frequency' => $validated['frequency'],
            'retention_count' => $validated['retention_count'],
            'enabled' => $validated['enabled'],
        ]);

        $schedule->update([
            'next_run_at' => $schedule->enabled ? $schedule->computeNextRunAt() : null,
        ]);

        return redirect()
            ->back()
            ->with('success', 'Backup schedule saved.');
    }
}
