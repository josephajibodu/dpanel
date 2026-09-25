<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBackupScheduleRequest;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class SiteBackupScheduleController extends Controller
{
    public function update(
        Team $team,
        Server $server,
        Site $site,
        UpdateBackupScheduleRequest $request,
    ): RedirectResponse {
        $this->authorize('view', $site);

        if (! $site->usesSqlite()) {
            return redirect()
                ->back()
                ->with('error', 'Only sites using SQLite can be backed up here.');
        }

        $validated = $request->validated();

        $schedule = $site->backupSchedule()->updateOrCreate([], [
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
