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
    ) {}

    public function handle(SshService $sshService): void
    {
        $site = $this->site;
        $server = $site->server;

        Log::info("Syncing environment variables for site {$site->domain}");

        try {
            // Load environment variables
            $variables = $site->environmentVariables()->get();

            if ($variables->isEmpty()) {
                Log::info("No environment variables to sync for site {$site->domain}");

                return;
            }

            // Build .env content
            $envContent = $variables->map(function ($var) {
                $value = $var->value;
                // Quote values with spaces or special characters
                if (preg_match('/[\s"\'#]/', $value) || str_contains($value, '=')) {
                    $value = '"'.addslashes($value).'"';
                }

                return "{$var->key}={$value}";
            })->implode("\n");

            $connection = $sshService->connect($server);

            $siteRoot = $site->rootPath();
            $envPath = "{$siteRoot}/.env";

            // Write .env file
            $escapedContent = str_replace("'", "'\\''", $envContent);
            $connection->exec("echo '{$escapedContent}' > {$envPath}");

            // Set proper permissions
            $serverUser = config('server.user');
            $connection->exec("chmod 600 {$envPath}");
            $connection->exec("chown {$serverUser}:{$serverUser} {$envPath}");

            $connection->disconnect();

            Log::info("Environment variables synced for site {$site->domain}");

        } catch (\Throwable $e) {
            Log::error("Failed to sync environment for site {$site->domain}: {$e->getMessage()}");

            throw $e;
        }
    }
}
