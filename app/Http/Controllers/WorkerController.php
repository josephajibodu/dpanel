<?php

namespace App\Http\Controllers;

use App\Actions\Worker\CreateWorker;
use App\Actions\Worker\DestroyWorker;
use App\Actions\Worker\UpdateWorker;
use App\Enums\WorkerControlAction;
use App\Http\Requests\StoreWorkerRequest;
use App\Http\Requests\UpdateWorkerRequest;
use App\Jobs\ControlWorkerJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\Worker;
use App\Services\Ssh\SshService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WorkerController extends Controller
{
    public function __construct(
        private SshService $sshService,
    ) {}

    public function store(StoreWorkerRequest $request, Team $team, Server $server): RedirectResponse
    {
        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to create workers.');
        }

        app(CreateWorker::class)->create($server, $request->validated());

        return redirect()
            ->back()
            ->with('success', 'Worker created.');
    }

    public function update(
        UpdateWorkerRequest $request,
        Team $team,
        Server $server,
        Worker $worker,
    ): RedirectResponse {
        $this->authorize('view', $server);

        app(UpdateWorker::class)->update($worker, $request->validated());

        return redirect()
            ->back()
            ->with('success', 'Worker updated.');
    }

    public function destroy(Team $team, Server $server, Worker $worker): RedirectResponse
    {
        $this->authorize('view', $server);

        app(DestroyWorker::class)->delete($worker);

        return redirect()
            ->back()
            ->with('success', 'Worker removed.');
    }

    public function start(Team $team, Server $server, Worker $worker): RedirectResponse
    {
        $this->authorize('view', $server);

        ControlWorkerJob::dispatch($worker, WorkerControlAction::Start);

        return redirect()
            ->back()
            ->with('success', 'Worker is starting.');
    }

    public function stop(Team $team, Server $server, Worker $worker): RedirectResponse
    {
        $this->authorize('view', $server);

        ControlWorkerJob::dispatch($worker, WorkerControlAction::Stop);

        return redirect()
            ->back()
            ->with('success', 'Worker is stopping.');
    }

    public function restart(Team $team, Server $server, Worker $worker): RedirectResponse
    {
        $this->authorize('view', $server);

        ControlWorkerJob::dispatch($worker, WorkerControlAction::Restart);

        return redirect()
            ->back()
            ->with('success', 'Worker is restarting.');
    }

    public function logs(Request $request, Team $team, Server $server, Worker $worker): Response
    {
        $this->authorize('view', $server);

        if (! $worker->stdout_logfile) {
            return response('Log file path not configured.', 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        try {
            $connection = $this->sshService->connect($server);
            $content = $connection->download($worker->stdout_logfile);
            $connection->disconnect();
        } catch (\Throwable) {
            $content = 'Could not retrieve log file from server.';
        }

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
