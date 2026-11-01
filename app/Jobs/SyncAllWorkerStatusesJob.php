<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fans out the worker status check to every active server that has workers.
 * Each server gets its own queued job so one slow or unreachable server
 * can't delay the rest.
 */
class SyncAllWorkerStatusesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): void
    {
        Server::query()
            ->where('status', ServerStatus::Active)
            ->whereHas('workers')
            ->get()
            ->each(fn (Server $server) => SyncWorkerStatusesJob::dispatch($server));
    }
}
