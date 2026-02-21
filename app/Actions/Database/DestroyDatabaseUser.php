<?php

namespace App\Actions\Database;

use App\Jobs\DestroyDatabaseUserJob;
use App\Models\DatabaseUser;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use RuntimeException;

class DestroyDatabaseUser
{
    use EscapesShell;

    public function __construct(
        private SshService $sshService
    ) {}

    public function delete(DatabaseUser $databaseUser): void
    {
        DestroyDatabaseUserJob::dispatch($databaseUser);
    }

    public function execute(DatabaseUser $databaseUser): void
    {
        $server = $databaseUser->server;

        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $credential = $server->credential('database_password');
        if (! $credential) {
            throw new RuntimeException("No database password credential for server {$server->id}.");
        }

        $rootPassword = $credential->value;
        $connection = $this->sshService->connect($server);

        try {
            $databaseType = $server->database_type;

            if ($databaseType === 'mysql' || $databaseType === 'mariadb') {
                $this->dropMysqlUser($connection, $databaseUser, $rootPassword);
            } elseif ($databaseType === 'postgresql') {
                $this->dropPostgresUser($connection, $databaseUser, $rootPassword);
            } else {
                throw new RuntimeException("Unsupported database type: {$databaseType}");
            }
        } finally {
            $connection->disconnect();
        }
    }

    private function dropMysqlUser(SshConnection $connection, DatabaseUser $databaseUser, string $rootPassword): void
    {
        $user = $databaseUser->username;
        $host = $databaseUser->host ?? 'localhost';

        $userEsc = str_replace("'", "''", $user);
        $hostEsc = str_replace("'", "''", $host);

        $dropSql = "DROP USER IF EXISTS '{$userEsc}'@'{$hostEsc}'";
        $cmd = 'sudo mysql -u root -p'.$this->escapeForShell($rootPassword).' -e '.$this->escapeForShell($dropSql);
        $connection->exec($cmd, 60);

        $cmd = 'sudo mysql -u root -p'.$this->escapeForShell($rootPassword).' -e "FLUSH PRIVILEGES;"';
        $connection->exec($cmd, 60);
    }

    private function dropPostgresUser(SshConnection $connection, DatabaseUser $databaseUser, string $postgresPassword): void
    {
        $user = $databaseUser->username;
        $userIdent = '"'.str_replace('"', '""', $user).'"';

        $dropSql = 'DROP USER IF EXISTS '.$userIdent;
        $cmd = 'PGPASSWORD='.$this->escapeForShell($postgresPassword).' sudo -u postgres psql -c '.$this->escapeForShell($dropSql);
        $connection->exec($cmd, 60);
    }
}
