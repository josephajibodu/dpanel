<?php

namespace App\Http\Controllers;

use App\Actions\CronJob\CreateCronJob;
use App\Actions\CronJob\DestroyCronJob;
use App\Actions\CronJob\DisableCronJob;
use App\Actions\CronJob\EnableCronJob;
use App\Actions\CronJob\UpdateCronJob;
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

        app(CreateCronJob::class)->create($server, $request->validated());

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

        app(UpdateCronJob::class)->update($cron_job, $request->validated());

        return redirect()
            ->back()
            ->with('success', 'Cron job updated.');
    }

    public function destroy(Server $server, CronJob $cron_job): RedirectResponse
    {
        $this->authorize('view', $server);

        app(DestroyCronJob::class)->delete($cron_job);

        return redirect()
            ->back()
            ->with('success', 'Cron job removed.');
    }

    public function disable(Server $server, CronJob $cron_job): RedirectResponse
    {
        $this->authorize('view', $server);

        app(DisableCronJob::class)->disable($cron_job);

        return redirect()
            ->back()
            ->with('success', 'Cron job disabled.');
    }

    public function enable(Server $server, CronJob $cron_job): RedirectResponse
    {
        $this->authorize('view', $server);

        app(EnableCronJob::class)->enable($cron_job);

        return redirect()
            ->back()
            ->with('success', 'Cron job enabled.');
    }
}
