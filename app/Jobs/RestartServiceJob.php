<?php

namespace App\Jobs;

use App\Enums\ServiceType;
use App\Models\Server;
use App\Models\ServerAction;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RestartServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 60;

    public function __construct(
        public Server $server,
        public ServiceType $service,
        public ?ServerAction $action = null,
    ) {
        $this->onQueue('ssh');
    }

    public function handle(SshService $sshService): void
    {
        $this->updateActionStatus('running');

        try {
            $connection = $sshService->connect($this->server);

            $command = $this->getRestartCommand();
            $output = $connection->sudo($command);

            $connection->disconnect();

            $this->updateActionStatus('finished', $output);

            Log::info("Service {$this->service->value} restarted on server {$this->server->id}");
        } catch (Throwable $e) {
            Log::error("Failed to restart {$this->service->value} on server {$this->server->id}: {$e->getMessage()}");

            $this->updateActionStatus('failed', null, $e->getMessage());

            throw $e;
        }
    }

    private function getRestartCommand(): string
    {
        return match ($this->service) {
            ServiceType::Nginx => 'systemctl restart nginx',
            ServiceType::Php => "systemctl restart php{$this->server->php_version}-fpm",
            ServiceType::Mysql => 'systemctl restart mysql',
            ServiceType::Postgresql => 'systemctl restart postgresql',
            ServiceType::Redis => 'systemctl restart redis-server',
            ServiceType::Supervisor => 'supervisorctl restart all',
        };
    }

    private function updateActionStatus(string $status, ?string $output = null, ?string $error = null): void
    {
        if (! $this->action) {
            return;
        }

        $data = ['status' => $status];

        if ($status === 'running') {
            $data['started_at'] = now();
        }

        if ($status === 'finished' || $status === 'failed') {
            $data['finished_at'] = now();
        }

        if ($output !== null) {
            $data['output'] = $output;
        }

        if ($error !== null) {
            $data['error'] = $error;
        }

        $this->action->update($data);
    }
}
