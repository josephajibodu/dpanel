<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncEnvironmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public Site $site,
        public bool $clearConfigCache = false,
        public bool $restartQueue = false,
    ) {}

    public function handle(SshService $sshService): void
    {
        $site = $this->site;
        $server = $site->server;

        Log::info("Syncing environment variables for site {$site->domain}");

        try {
            $envPath = "{$site->sharedPath()}/.env";

            // Use stored raw content when available; fall back to rebuilding from key-value pairs
            // for sites that predate the env_content column.
            if ($site->env_content !== null) {
                $envContent = $site->env_content;
            } else {
                $variables = $site->environmentVariables()->get();

                if ($variables->isEmpty()) {
                    Log::info("No environment variables to sync for site {$site->domain}");

                    return;
                }

                $envContent = $variables->map(fn ($var) => "{$var->key}={$var->value}")->implode("\n");
            }

            $connection = $sshService->connect($server);

            // Write .env file using upload to avoid shell escaping issues.
            $connection->upload($envContent, $envPath);

            // Set proper permissions
            $serverUser = config('server.user');
            $connection->exec("chmod 600 {$envPath}");
            $connection->exec("chown {$serverUser}:{$serverUser} {$envPath}");

            $phpVersion = $site->php_version ?? '8.4';
            $phpBinary = "php{$phpVersion}";

            // .env is symlinked into whichever release `current` points at, so
            // artisan needs to run from there (the site root itself holds no
            // application code — see releases/current/shared under Site::rootPath()).
            $currentPath = $site->currentPath();

            if ($this->clearConfigCache) {
                $connection->exec("cd {$currentPath} && {$phpBinary} artisan config:cache");
            }

            if ($this->restartQueue) {
                $connection->exec("cd {$currentPath} && {$phpBinary} artisan queue:restart");
            }

            $connection->disconnect();

            Log::info("Environment variables synced for site {$site->domain}");

        } catch (\Throwable $e) {
            Log::error("Failed to sync environment for site {$site->domain}: {$e->getMessage()}");

            throw $e;
        }
    }
}
