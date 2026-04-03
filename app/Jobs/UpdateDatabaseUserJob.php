<?php

namespace App\Jobs;

use App\Actions\Database\UpdateDatabaseUser;
use App\Events\ServerDatabasesUpdated;
use App\Models\DatabaseUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateDatabaseUserJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public DatabaseUser $databaseUser
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(UpdateDatabaseUser $action): void
    {
        try {
            $action->execute($this->databaseUser);
            $this->databaseUser->update(['status' => 'ready']);
        } catch (\Throwable $e) {
            Log::error('UpdateDatabaseUserJob failed', [
                'database_user_id' => $this->databaseUser->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            event(new ServerDatabasesUpdated($this->databaseUser->server));
        }
    }
}
