<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dead man's switch for Flitops's own background processing. Scheduled
 * periodically and run on the queue, so a ping only reaches the external
 * monitor (e.g. Healthchecks.io, Better Stack) when both the scheduler and
 * the queue workers are alive. When pings stop, the monitor alerts.
 */
class SendSchedulerHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function handle(): void
    {
        $url = config('services.heartbeat.url');

        if (blank($url)) {
            return;
        }

        try {
            $response = Http::timeout(10)->get($url);
        } catch (ConnectionException $e) {
            Log::warning("Scheduler heartbeat ping failed: {$e->getMessage()}");

            return;
        }

        if ($response->failed()) {
            Log::warning("Scheduler heartbeat ping returned HTTP {$response->status()}");
        }
    }
}
