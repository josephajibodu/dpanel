<?php

namespace App\Jobs;

use App\Events\SiteNginxUpdated;
use App\Models\Site;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncSiteNginxFilesJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public Site $site)
    {
        $this->onQueue('provisioning');
    }

    public function handle(SshService $sshService): void
    {
        $this->site->load('nginxFiles');

        $connection = $sshService->connect($this->site->server);

        try {
            foreach ($this->site->nginxFiles as $file) {
                $absPath = $file->absolutePath();

                try {
                    $content = $connection->exec("sudo cat {$absPath} 2>/dev/null || true");

                    if ($content === '') {
                        continue;
                    }

                    $hash = md5($content);

                    if ($hash !== $file->hash) {
                        $file->update([
                            'content' => $content,
                            'hash' => $hash,
                            'last_synced_at' => now(),
                            'sync_status' => 'synced',
                            'sync_error' => null,
                        ]);
                    } else {
                        $file->update(['last_synced_at' => now()]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('SyncSiteNginxFilesJob: could not read file', [
                        'path' => $absPath,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $connection->disconnect();
            broadcast(new SiteNginxUpdated($this->site));
        }
    }
}
