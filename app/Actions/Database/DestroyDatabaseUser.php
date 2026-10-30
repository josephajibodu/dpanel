<?php

namespace App\Actions\Database;

use App\Events\ServerDatabasesUpdated;
use App\Jobs\DestroyDatabaseUserJob;
use App\Models\DatabaseUser;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use RuntimeException;

class DestroyDatabaseUser
{
    use EscapesShell, ManagesRemoteMysqlAccounts, QuotesMysql, RunsSqlPrivately;

    public function __construct(
        private SshService $sshService
    ) {}

    public function delete(DatabaseUser $databaseUser): void
    {
        $databaseUser->update(['status' => 'deleting']);
        $server = $databaseUser->server;
        if ($server) {
            event(new ServerDatabasesUpdated($server));
        }
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
                $this->dropPostgresUser($connection, $databaseUser);
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

        $statements = ["DROP USER IF EXISTS {$this->mysqlString($user)}@{$this->mysqlString($host)}"];

        // The account other servers sign in with while they're connected.
        if ($this->needsRemoteMysqlAccount($databaseUser)) {
            $statements[] = "DROP USER IF EXISTS {$this->remoteMysqlAccount($databaseUser)}";
        }

        $statements[] = 'FLUSH PRIVILEGES';

        $this->runMysqlAsRoot($connection, $rootPassword, $statements);
    }

    /**
     * Postgres refuses to drop a role that still holds privileges or owns
     * tables, so each of the user's databases is cleared first: anything they
     * own (the app's tables, if migrations ran as them) is handed to the
     * postgres superuser so no data is lost, then their remaining grants and
     * default-privilege rules are dropped. The role check keeps this working
     * when the user was never created on the server.
     */
    private function dropPostgresUser(SshConnection $connection, DatabaseUser $databaseUser): void
    {
        $user = $databaseUser->username;
        $userIdent = '"'.str_replace('"', '""', $user).'"';
        $userLiteral = "'".str_replace("'", "''", $user)."'";

        foreach ($databaseUser->databases ?? [] as $dbName) {
            $this->runPostgresAsSuperuser($connection, [
                'DO $$ BEGIN '
                ."IF EXISTS (SELECT FROM pg_roles WHERE rolname = {$userLiteral}) THEN "
                ."EXECUTE format('REASSIGN OWNED BY %I TO postgres', {$userLiteral}); "
                ."EXECUTE format('DROP OWNED BY %I', {$userLiteral}); "
                .'END IF; END $$',
            ], $dbName);
        }

        $this->runPostgresAsSuperuser($connection, ['DROP USER IF EXISTS '.$userIdent]);
    }
}
