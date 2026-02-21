<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkerRequest;
use App\Http\Requests\UpdateWorkerRequest;
use App\Models\Server;
use App\Models\Worker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class WorkerController extends Controller
{
    public function store(StoreWorkerRequest $request, Server $server): RedirectResponse
    {
        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to create workers.');
        }

        $validated = $request->validated();

        $server->workers()->create([
            'name' => $validated['name'],
            'command' => $validated['command'],
            'site_id' => $validated['site_id'] ?? null,
            'user' => $validated['user'],
            'numprocs' => $validated['numprocs'],
            'auto_start' => $validated['auto_start'] ?? true,
            'auto_restart' => $validated['auto_restart'] ?? true,
            'redirect_stderr' => $validated['redirect_stderr'] ?? true,
            'stdout_logfile' => $validated['stdout_logfile'] ?? null,
            'status' => 'stopped',
        ]);

        return redirect()
            ->back()
            ->with('success', 'Worker created.');
    }

    public function update(
        UpdateWorkerRequest $request,
        Server $server,
        Worker $worker,
    ): RedirectResponse {
        $this->authorize('view', $server);

        $validated = $request->validated();

        $worker->update([
            'name' => $validated['name'],
            'command' => $validated['command'],
            'site_id' => $validated['site_id'] ?? null,
            'user' => $validated['user'],
            'numprocs' => $validated['numprocs'],
            'auto_start' => $validated['auto_start'] ?? true,
            'auto_restart' => $validated['auto_restart'] ?? true,
            'redirect_stderr' => $validated['redirect_stderr'] ?? true,
            'stdout_logfile' => $validated['stdout_logfile'] ?? null,
        ]);

        return redirect()
            ->back()
            ->with('success', 'Worker updated.');
    }

    public function destroy(Server $server, Worker $worker): RedirectResponse
    {
        $this->authorize('view', $server);

        $worker->delete();

        return redirect()
            ->back()
            ->with('success', 'Worker removed.');
    }

    public function start(Server $server, Worker $worker): RedirectResponse
    {
        $this->authorize('view', $server);

        $worker->update(['status' => 'active']);

        return redirect()
            ->back()
            ->with('success', 'Worker started.');
    }

    public function stop(Server $server, Worker $worker): RedirectResponse
    {
        $this->authorize('view', $server);

        $worker->update(['status' => 'stopped']);

        return redirect()
            ->back()
            ->with('success', 'Worker stopped.');
    }

    public function restart(Server $server, Worker $worker): RedirectResponse
    {
        $this->authorize('view', $server);

        $worker->update(['status' => 'active']);

        return redirect()
            ->back()
            ->with('success', 'Worker restarted.');
    }

    public function logs(Request $request, Server $server, Worker $worker): Response
    {
        $this->authorize('view', $server);

        $path = $worker->stdout_logfile;
        $content = '';
        if ($path && File::exists($path)) {
            $content = File::get($path);
        } else {
            $content = "Log file not available.\nPath: ".($path ?? 'not set');
        }

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
