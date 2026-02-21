<?php

namespace App\Jobs;

use App\Actions\Database\CreateDatabaseUser;
use App\Models\DatabaseUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateDatabaseUserJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public DatabaseUser $databaseUser
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(CreateDatabaseUser $action): void
    {
        try {
            $action->execute($this->databaseUser);
            $this->databaseUser->update(['status' => 'ready']);
        } catch (\Throwable $e) {
            Log::error('CreateDatabaseUserJob failed', [
                'database_user_id' => $this->databaseUser->id,
                'message' => $e->getMessage(),
            ]);
            $this->databaseUser->update(['status' => 'failed']);
            throw $e;
        }
    }
}
