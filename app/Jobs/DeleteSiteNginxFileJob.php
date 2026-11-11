<?php

namespace App\Jobs;

use App\Enums\NginxHistoryEvent;
use App\Events\SiteNginxUpdated;
use App\Models\SiteNginxFile;
use App\Models\SiteNginxHistory;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteSiteNginxFileJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public SiteNginxFile $nginxFile,
        public ?int $userId,
    ) {
        $this->onQueue('provisioning');
    }

    /**
     * The file is set aside, not removed, until the web server accepts the
     * config without it. Another file may still rely on what it defines, and
     * removing it then would leave a config the web server can't reload.
     */
    public function handle(SshService $sshService): void
    {
        $site = $this->nginxFile->site;
        $absolutePath = $this->nginxFile->absolutePath();
        $backupPath = $absolutePath.'.flitops-bak';

        try {
            $connection = $sshService->connect($site->server);
            $webServer = $site->server->webServer();

            try {
                $webServer->withConfigLock($site->server, function () use ($connection, $webServer, $absolutePath, $backupPath): void {
                    $connection->exec("sudo mv -f {$absolutePath} {$backupPath} 2>/dev/null || true");

                    try {
                        $webServer->validate($connection);
                    } catch (\RuntimeException $validationException) {
                        $connection->exec("sudo mv -f {$backupPath} {$absolutePath} 2>/dev/null || true");

                        throw $validationException;
                    }

                    $connection->exec("sudo rm -f {$backupPath}");
                    $webServer->reload($connection);
                });
            } finally {
                $connection->disconnect();
            }

            SiteNginxHistory::create([
                'site_id' => $site->id,
                'site_nginx_file_id' => null,
                'user_id' => $this->userId,
                'event' => NginxHistoryEvent::Deleted,
                'path' => $this->nginxFile->path,
                'old_content' => $this->nginxFile->content,
                'new_content' => null,
            ]);

            $this->nginxFile->delete();
        } catch (\Throwable $e) {
            Log::error('DeleteSiteNginxFileJob failed', [
                'nginx_file_id' => $this->nginxFile->id,
                'message' => $e->getMessage(),
            ]);
            $this->nginxFile->update(['sync_status' => 'failed', 'sync_error' => $e->getMessage()]);

            throw $e;
        } finally {
            broadcast(new SiteNginxUpdated($site));
        }
    }
}
