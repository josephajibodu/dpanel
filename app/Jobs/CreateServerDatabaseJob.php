<?php

namespace App\Jobs;

use App\Actions\Database\CreateServerDatabase;
use App\Models\ServerDatabase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateServerDatabaseJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public ServerDatabase $serverDatabase
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(CreateServerDatabase $action): void
    {
        try {
            $action->execute($this->serverDatabase);
            $this->serverDatabase->update(['status' => 'ready']);
        } catch (\Throwable $e) {
            Log::error('CreateServerDatabaseJob failed', [
                'server_database_id' => $this->serverDatabase->id,
                'message' => $e->getMessage(),
            ]);
            $this->serverDatabase->update(['status' => 'failed']);
            throw $e;
        }
    }
}
