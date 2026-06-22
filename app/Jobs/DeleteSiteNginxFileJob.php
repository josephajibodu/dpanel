<?php

namespace App\Jobs;

use App\Enums\NginxHistoryEvent;
use App\Events\SiteNginxUpdated;
use App\Models\Site;
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

    public function __construct(
        public Site $site,
        public string $absolutePath,
        public string $path,
        public ?string $deletedContent,
        public ?int $userId,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(SshService $sshService): void
    {
        broadcast(new SiteNginxUpdated($this->site));

        try {
            $connection = $sshService->connect($this->site->server);

            try {
                $connection->exec("sudo rm -f {$this->absolutePath}");
                $connection->exec('sudo systemctl reload nginx');
            } finally {
                $connection->disconnect();
            }

            SiteNginxHistory::create([
                'site_id' => $this->site->id,
                'site_nginx_file_id' => null,
                'user_id' => $this->userId,
                'event' => NginxHistoryEvent::Deleted,
                'path' => $this->path,
                'old_content' => $this->deletedContent,
                'new_content' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('DeleteSiteNginxFileJob failed', [
                'site_id' => $this->site->id,
                'path' => $this->path,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            broadcast(new SiteNginxUpdated($this->site));
        }
    }
}
