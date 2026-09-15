<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fans out metrics collection to every active server. Scheduled periodically;
 * each server's SSH poll runs as its own queued job so one slow or
 * unreachable server can't delay the rest.
 */
class CollectAllServerMetricsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): void
    {
        Server::query()
            ->where('status', ServerStatus::Active)
            ->get()
            ->each(fn (Server $server) => CollectServerMetricsJob::dispatch($server));
    }
}
