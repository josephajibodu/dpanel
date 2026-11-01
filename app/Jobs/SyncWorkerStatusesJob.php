<?php

namespace App\Jobs;

use App\Events\ServerProcessesUpdated;
use App\Models\Server;
use App\Models\Worker;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Asks Supervisor what is really running on a server and updates each
 * worker's status to match, so FlitOps notices workers that were stopped,
 * started or crashed outside of FlitOps (by hand, or by a deploy script).
 */
class SyncWorkerStatusesJob implements ShouldQueue
{
    use Queueable;

    /**
     * Statuses the check may overwrite. Any other status means FlitOps is in
     * the middle of changing the worker, and that job sets the status itself.
     */
    public const SETTLED_STATUSES = ['active', 'stopped', 'crashed'];

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public Server $server,
    ) {
        $this->onQueue('ssh');
    }

    public function handle(SshService $sshService): void
    {
        try {
            $connection = $sshService->connect($this->server);

            // supervisorctl exits non-zero whenever a program isn't running,
            // which is exactly the case we want to read.
            $output = $connection->sudo('supervisorctl status || true', 20);

            $connection->disconnect();
        } catch (\Throwable $e) {
            Log::warning('Failed to check worker statuses.', [
                'server_id' => $this->server->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $statesByWorker = $this->parseSupervisorStates($output);
        $changed = false;

        $workers = $this->server->workers()->whereIn('status', self::SETTLED_STATUSES)->get();

        foreach ($workers as $worker) {
            if (! isset($statesByWorker[$worker->id])) {
                continue;
            }

            $actualStatus = $this->statusFromStates($statesByWorker[$worker->id]);

            if ($actualStatus === $worker->status) {
                continue;
            }

            // Only overwrite the status we read, so an action started from
            // FlitOps since then isn't clobbered.
            $changed = Worker::query()
                ->whereKey($worker->id)
                ->where('status', $worker->status)
                ->update(['status' => $actualStatus]) > 0 || $changed;
        }

        if ($changed) {
            event(new ServerProcessesUpdated($this->server->id));
        }
    }

    /**
     * Group the process states in `supervisorctl status` output by worker id.
     * A worker with several processes shows one line per process, named
     * `flitops-worker-12:flitops-worker-12_00`.
     *
     * @return array<int, list<string>>
     */
    private function parseSupervisorStates(string $output): array
    {
        $pattern = '/^'.preg_quote(config('supervisor.file_prefix'), '/').'-(\d+)(?::\S+)?\s+([A-Z]+)/m';

        preg_match_all($pattern, $output, $matches, PREG_SET_ORDER);

        $states = [];

        foreach ($matches as [, $workerId, $state]) {
            $states[(int) $workerId][] = $state;
        }

        return $states;
    }

    /**
     * Reduce a worker's process states to one FlitOps status. Any process that
     * died and isn't coming back on its own counts as a crash.
     *
     * @param  list<string>  $states
     */
    private function statusFromStates(array $states): string
    {
        if (array_intersect($states, ['FATAL', 'BACKOFF', 'EXITED', 'UNKNOWN']) !== []) {
            return 'crashed';
        }

        if (array_intersect($states, ['RUNNING', 'STARTING']) !== []) {
            return 'active';
        }

        return 'stopped';
    }
}
