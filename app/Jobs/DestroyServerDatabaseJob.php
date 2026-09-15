<?php

namespace App\Jobs;

use App\Actions\Database\DestroyServerDatabase;
use App\Events\ServerDatabasesUpdated;
use App\Models\DatabaseUser;
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
        $databaseName = $this->serverDatabase->name;

        try {
            $action->execute($this->serverDatabase);
            $this->serverDatabase->delete();
            $this->pruneFromDatabaseUsers($server->databaseUsers, $databaseName);
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

    /**
     * Drop the deleted database's name from every user's database list so it
     * stops showing up as still-granted access once the database is gone.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, DatabaseUser>  $databaseUsers
     */
    private function pruneFromDatabaseUsers($databaseUsers, string $databaseName): void
    {
        foreach ($databaseUsers as $databaseUser) {
            if (! in_array($databaseName, $databaseUser->databases ?? [], true)) {
                continue;
            }

            $databaseUser->update([
                'databases' => array_values(array_diff($databaseUser->databases, [$databaseName])),
            ]);
        }
    }
}
