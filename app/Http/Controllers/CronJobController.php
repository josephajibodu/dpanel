<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCronJobRequest;
use App\Http\Requests\UpdateCronJobRequest;
use App\Models\CronJob;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;

class CronJobController extends Controller
{
    public function store(StoreCronJobRequest $request, Server $server): RedirectResponse
    {
        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to create cron jobs.');
        }

        $validated = $request->validated();

        $server->cronJobs()->create([
            'command' => $validated['command'],
            'site_id' => $validated['site_id'] ?? null,
            'user' => $validated['user'],
            'frequency' => $validated['frequency'],
            'hidden' => false,
            'status' => 'active',
        ]);

        return redirect()
            ->back()
            ->with('success', 'Cron job created.');
    }

    public function update(
        UpdateCronJobRequest $request,
        Server $server,
        CronJob $cron_job,
    ): RedirectResponse {
        $this->authorize('view', $server);

        $validated = $request->validated();

        $cron_job->update([
            'command' => $validated['command'],
            'site_id' => $validated['site_id'] ?? null,
            'user' => $validated['user'],
            'frequency' => $validated['frequency'],
        ]);

        return redirect()
            ->back()
            ->with('success', 'Cron job updated.');
    }

    public function destroy(Server $server, CronJob $cron_job): RedirectResponse
    {
        $this->authorize('view', $server);

        $cron_job->delete();

        return redirect()
            ->back()
            ->with('success', 'Cron job removed.');
    }

    public function disable(Server $server, CronJob $cron_job): RedirectResponse
    {
        $this->authorize('view', $server);

        $cron_job->update(['hidden' => true]);

        return redirect()
            ->back()
            ->with('success', 'Cron job disabled.');
    }

    public function enable(Server $server, CronJob $cron_job): RedirectResponse
    {
        $this->authorize('view', $server);

        $cron_job->update(['hidden' => false]);

        return redirect()
            ->back()
            ->with('success', 'Cron job enabled.');
    }
}
