<?php

namespace App\Jobs;

use App\Actions\Database\EscapesShell;
use App\Actions\Worker\CreateWorker;
use App\Actions\Worker\DestroyWorker;
use App\Events\ServerProcessesUpdated;
use App\Exceptions\SshCommandException;
use App\Models\Site;
use App\Notifications\SiteOctaneFailed;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Makes a site's server match its Octane setting. Switched on, the Octane
 * process is started under Supervisor and the web server is pointed at it;
 * switched off, the web server goes back to PHP-FPM and the process is
 * removed. A switch-on that fails is undone, and the server's owner is
 * emailed the reason.
 */
class SyncSiteOctaneJob implements ShouldQueue
{
    use EscapesShell, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Installs the FrankenPHP program for every site on the server, unless
     * it's already there. Octane finds it on the PATH.
     */
    private const INSTALL_FRANKENPHP = <<<'BASH'
set -e
if command -v frankenphp > /dev/null; then exit 0; fi
command -v curl > /dev/null || apt-get install -y curl
case "$(uname -m)" in
    x86_64) asset=frankenphp-linux-x86_64-gnu ;;
    aarch64|arm64) asset=frankenphp-linux-aarch64-gnu ;;
    *) echo "FrankenPHP has no build for this server's processor." >&2; exit 1 ;;
esac
curl -fsSL -o /usr/local/bin/frankenphp.download "https://github.com/php/frankenphp/releases/latest/download/${asset}"
chmod 755 /usr/local/bin/frankenphp.download
mv -f /usr/local/bin/frankenphp.download /usr/local/bin/frankenphp
BASH;

    public int $tries = 1;

    public int $timeout = 420;

    public function __construct(public Site $site)
    {
        $this->onQueue('provisioning');
    }

    public function handle(SshService $sshService, CreateWorker $createWorker, DestroyWorker $destroyWorker): void
    {
        $site = $this->site->fresh();

        if ($site === null) {
            return;
        }

        try {
            if (! $site->usesOctane()) {
                $this->turnOff($site, $destroyWorker);

                return;
            }

            try {
                $this->turnOn($site, $sshService, $createWorker);
            } catch (Throwable $exception) {
                Log::error('Octane could not be switched on', ['site_id' => $site->id, 'message' => $exception->getMessage()]);
                $cleanupFinished = $this->undoTurnOn($site, $destroyWorker);

                $server = $site->server;
                ($server->team?->owner ?? $server->user)?->notify(
                    new SiteOctaneFailed($site, $server->redactSecrets($exception->getMessage()), $cleanupFinished),
                );
            }
        } finally {
            event(new ServerProcessesUpdated($site->server_id));
        }
    }

    /**
     * Start the Octane process, then point the web server at it. The order
     * matters: the process must be answering before visitors are sent to it.
     */
    private function turnOn(Site $site, SshService $sshService, CreateWorker $createWorker): void
    {
        $worker = $site->octaneWorker;

        if ($worker === null) {
            throw new RuntimeException('The Octane process for this site is missing.');
        }

        $connection = $sshService->connect($site->server);

        try {
            $package = $site->currentPath().'/vendor/laravel/octane';
            $installed = trim($connection->exec($site->commandAsSystemUser("test -d {$package} && echo yes || true"), 15)) === 'yes';

            if (! $installed) {
                throw new RuntimeException("The site's code doesn't include Laravel Octane. Run \"composer require laravel/octane\" in the project, deploy, then switch Octane on again.");
            }

            $connection->exec('sudo bash -c '.$this->escapeForShell(self::INSTALL_FRANKENPHP), 300);
        } finally {
            $connection->disconnect();
        }

        $worker->update(['command' => $site->octaneCommand(), 'user' => $site->systemUser()]);
        $createWorker->execute($worker);

        $connection = $sshService->connect($site->server);

        try {
            try {
                $connection->exec('bash -c '.$this->escapeForShell($this->waitForPortScript($site->octane_port)), 60);
            } catch (SshCommandException) {
                throw new RuntimeException("Octane didn't start. Open the site's Processes page and read the Octane process's log.");
            }

            $site->server->webServer()->syncSite($site, $connection);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * Switch Octane back off after a switch-on failed. When the server stops
     * answering partway, the undo can't finish either: the setting is still
     * switched off and the Octane process is marked failed, so the owner
     * still hears what happened and the next save of the site's settings
     * removes what was left behind (see SetOctaneAction).
     *
     * @return bool Whether the undo finished.
     */
    private function undoTurnOn(Site $site, DestroyWorker $destroyWorker): bool
    {
        try {
            $this->turnOff($site, $destroyWorker);

            return true;
        } catch (Throwable $exception) {
            Log::error('Octane could not be switched back off after a failed switch-on', ['site_id' => $site->id, 'message' => $exception->getMessage()]);

            $site->update(['octane_server' => null]);
            $site->octaneWorker()->update(['status' => 'failed']);

            return false;
        }
    }

    /**
     * Send the site's visitors back to PHP-FPM, then remove the Octane
     * process. The web server goes first, so nobody is sent to a process
     * that has stopped.
     */
    private function turnOff(Site $site, DestroyWorker $destroyWorker): void
    {
        if ($site->octane_server !== null) {
            $site->update(['octane_server' => null]);
        }

        $site->server->webServer()->syncSite($site);

        $worker = $site->octaneWorker()->first();

        if ($worker !== null) {
            $destroyWorker->execute($worker);
            $worker->delete();
        }

        $site->update(['octane_port' => null]);
    }

    /**
     * A script that waits up to 30 seconds for something to answer on the
     * port, and fails if nothing does.
     */
    private function waitForPortScript(int $port): string
    {
        return "for attempt in \$(seq 1 30); do if (exec 3<>/dev/tcp/127.0.0.1/{$port}) 2>/dev/null; then exit 0; fi; sleep 1; done; exit 1";
    }
}
