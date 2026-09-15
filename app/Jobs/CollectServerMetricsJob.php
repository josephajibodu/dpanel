<?php

namespace App\Jobs;

use App\Events\ServerMetricsUpdated;
use App\Models\Server;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CollectServerMetricsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public Server $server,
    ) {}

    public function handle(SshService $sshService): void
    {
        try {
            $connection = $sshService->connect($this->server);

            $load = trim($connection->exec("cut -d' ' -f1 /proc/loadavg"));
            $memory = trim($connection->exec("free -b | awk '/^Mem:/{print \$2, \$3, \$7}'"));
            $disk = trim($connection->exec('df -B1 --output=size,used,avail / | tail -n 1'));

            $connection->disconnect();
        } catch (\Throwable $e) {
            Log::warning('Failed to collect server metrics.', [
                'server_id' => $this->server->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        [$memoryTotal, $memoryUsed, $memoryFree] = array_map('intval', preg_split('/\s+/', $memory));
        [$diskTotal, $diskUsed, $diskFree] = array_map('intval', preg_split('/\s+/', $disk));

        $metric = $this->server->metrics()->create([
            'load' => (float) $load,
            'memory_total' => $memoryTotal,
            'memory_used' => $memoryUsed,
            'memory_free' => $memoryFree,
            'disk_total' => $diskTotal,
            'disk_used' => $diskUsed,
            'disk_free' => $diskFree,
        ]);

        event(new ServerMetricsUpdated($this->server, $metric));
    }
}
