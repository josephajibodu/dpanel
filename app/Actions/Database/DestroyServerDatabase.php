<?php

namespace App\Actions\Database;

use App\Events\ServerDatabasesUpdated;
use App\Jobs\DestroyServerDatabaseJob;
use App\Models\ServerDatabase;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use RuntimeException;

class DestroyServerDatabase
{
    use EscapesShell, QuotesMysql, RunsSqlPrivately;

    public function __construct(
        private SshService $sshService
    ) {}

    public function delete(ServerDatabase $serverDatabase): void
    {
        $serverDatabase->update(['status' => 'deleting']);
        $server = $serverDatabase->server;
        if ($server) {
            event(new ServerDatabasesUpdated($server));
        }
        DestroyServerDatabaseJob::dispatch($serverDatabase);
    }

    public function execute(ServerDatabase $serverDatabase): void
    {
        $server = $serverDatabase->server;

        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $credential = $server->credential('database_password');
        if (! $credential) {
            throw new RuntimeException("No database password credential for server {$server->id}.");
        }

        $rootPassword = $credential->value;
        $name = $serverDatabase->name;
        $connection = $this->sshService->connect($server);

        try {
            $databaseType = $server->database_type;

            if ($databaseType === 'mysql' || $databaseType === 'mariadb') {
                $this->dropMysqlDatabase($connection, $name, $rootPassword);
            } elseif ($databaseType === 'postgresql') {
                $this->dropPostgresDatabase($connection, $name);
            } else {
                throw new RuntimeException("Unsupported database type: {$databaseType}");
            }
        } finally {
            $connection->disconnect();
        }
    }

    private function dropMysqlDatabase(SshConnection $connection, string $name, string $rootPassword): void
    {
        $this->runMysqlAsRoot($connection, $rootPassword, ["DROP DATABASE IF EXISTS {$this->mysqlIdentifier($name)}"]);
    }

    private function dropPostgresDatabase(SshConnection $connection, string $name): void
    {
        $escapedName = str_replace('"', '""', $name);

        $this->runPostgresAsSuperuser($connection, ['DROP DATABASE IF EXISTS "'.$escapedName.'"']);
    }
}
