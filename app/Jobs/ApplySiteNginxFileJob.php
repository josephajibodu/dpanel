<?php

namespace App\Jobs;

use App\Enums\NginxFileSection;
use App\Enums\NginxHistoryEvent;
use App\Events\SiteNginxUpdated;
use App\Exceptions\SshCommandException;
use App\Models\SiteNginxFile;
use App\Models\SiteNginxHistory;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplySiteNginxFileJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public SiteNginxFile $nginxFile,
        public NginxHistoryEvent $historyEvent,
        public ?string $oldContent,
        public ?string $oldPath,
        public ?int $userId,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(SshService $sshService): void
    {
        $this->nginxFile->refresh();
        $site = $this->nginxFile->site;

        $this->nginxFile->update(['sync_status' => 'syncing']);
        broadcast(new SiteNginxUpdated($site));

        try {
            $connection = $sshService->connect($site->server);

            try {
                $newAbsPath = $this->nginxFile->absolutePath();
                $newDir = dirname($newAbsPath);
                $content = $this->nginxFile->content ?? '';
                $escapedContent = str_replace("'", "'\\''", $content);

                $connection->exec("sudo mkdir -p {$newDir}");

                if ($this->historyEvent === NginxHistoryEvent::Updated) {
                    $backupPath = $newAbsPath.'.flitops-bak';
                    $connection->exec("sudo cp -f {$newAbsPath} {$backupPath} 2>/dev/null || true");

                    $connection->exec("echo '{$escapedContent}' | sudo tee {$newAbsPath} > /dev/null");

                    try {
                        $connection->exec('sudo nginx -t 2>&1');
                    } catch (SshCommandException $testException) {
                        $connection->exec("sudo mv -f {$backupPath} {$newAbsPath} 2>/dev/null || true");
                        throw new \RuntimeException('Nginx validation failed: '.$testException->output);
                    }

                    $connection->exec("sudo rm -f {$backupPath}");
                } elseif ($this->historyEvent === NginxHistoryEvent::Renamed && $this->oldPath) {
                    $connection->exec("echo '{$escapedContent}' | sudo tee {$newAbsPath} > /dev/null");

                    try {
                        $connection->exec('sudo nginx -t 2>&1');
                    } catch (SshCommandException $testException) {
                        $connection->exec("sudo rm -f {$newAbsPath}");
                        throw new \RuntimeException('Nginx validation failed: '.$testException->output);
                    }

                    $oldAbsPath = $this->oldAbsPath();
                    $connection->exec("sudo rm -f {$oldAbsPath}");
                } else {
                    $connection->exec("echo '{$escapedContent}' | sudo tee {$newAbsPath} > /dev/null");

                    try {
                        $connection->exec('sudo nginx -t 2>&1');
                    } catch (SshCommandException $testException) {
                        $connection->exec("sudo rm -f {$newAbsPath}");
                        throw new \RuntimeException('Nginx validation failed: '.$testException->output);
                    }
                }

                $connection->exec('sudo systemctl reload nginx');
            } finally {
                $connection->disconnect();
            }

            $this->nginxFile->update([
                'sync_status' => 'synced',
                'sync_error' => null,
                'hash' => md5($this->nginxFile->content ?? ''),
                'last_synced_at' => now(),
            ]);

            SiteNginxHistory::create([
                'site_id' => $site->id,
                'site_nginx_file_id' => $this->nginxFile->id,
                'user_id' => $this->userId,
                'event' => $this->historyEvent,
                'path' => $this->nginxFile->path,
                'old_path' => $this->oldPath !== null && $this->oldPath !== $this->nginxFile->path ? $this->oldPath : null,
                'old_content' => $this->oldContent,
                'new_content' => $this->nginxFile->content,
            ]);
        } catch (\Throwable $e) {
            Log::error('ApplySiteNginxFileJob failed', [
                'nginx_file_id' => $this->nginxFile->id,
                'message' => $e->getMessage(),
            ]);
            $this->nginxFile->update(['sync_status' => 'failed', 'sync_error' => $e->getMessage()]);
            throw $e;
        } finally {
            broadcast(new SiteNginxUpdated($site));
        }
    }

    private function oldAbsPath(): string
    {
        if ($this->nginxFile->section === NginxFileSection::Main) {
            return '/etc/nginx/sites-available/'.$this->oldPath;
        }

        $this->nginxFile->loadMissing('domain.site');

        if ($this->nginxFile->domain === null) {
            return $this->oldPath;
        }

        return $this->nginxFile->domain->nginxSnippetsBasePath().'/'.$this->oldPath;
    }
}
