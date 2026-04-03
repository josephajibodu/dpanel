<?php

namespace App\Jobs;

use App\Actions\Database\DestroyServerDatabase;
use App\Events\ServerDatabasesUpdated;
use App\Models\ServerDatabase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DestroyServerDatabaseJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public ServerDatabase $serverDatabase
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(DestroyServerDatabase $action): void
    {
        $server = $this->serverDatabase->server;

        try {
            $action->execute($this->serverDatabase);
            $this->serverDatabase->delete();
        } catch (\Throwable $e) {
            Log::error('DestroyServerDatabaseJob failed', [
                'server_database_id' => $this->serverDatabase->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            event(new ServerDatabasesUpdated($server));
        }
    }
}
