<?php

namespace App\Actions\Database;

use App\Jobs\CreateDatabaseUserJob;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use RuntimeException;

class CreateDatabaseUser
{
    use EscapesShell, GrantsPostgresPrivileges, QuotesMysql, RunsSqlPrivately;

    public function __construct(
        private SshService $sshService
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Server $server, array $input): DatabaseUser
    {
        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $databaseUser = $server->databaseUsers()->create([
            'username' => $input['username'],
            'password' => $input['password'],
            'databases' => $input['databases'],
            'permission' => $input['permission'] ?? 'readwrite',
            'host' => $input['host'] ?? 'localhost',
            'status' => 'pending',
        ]);

        CreateDatabaseUserJob::dispatch($databaseUser);

        return $databaseUser;
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

        $databases = $databaseUser->databases ?? [];
        if (empty($databases)) {
            throw new RuntimeException("Database user {$databaseUser->id} has no databases.");
        }

        $rootPassword = $credential->value;
        $connection = $this->sshService->connect($server);

        try {
            $databaseType = $server->database_type;

            if ($databaseType === 'mysql' || $databaseType === 'mariadb') {
                $this->createMysqlUser($connection, $databaseUser, $rootPassword);
            } elseif ($databaseType === 'postgresql') {
                $this->createPostgresUser($connection, $databaseUser);
            } else {
                throw new RuntimeException("Unsupported database type: {$databaseType}");
            }
        } finally {
            $connection->disconnect();
        }
    }

    private function createMysqlUser(SshConnection $connection, DatabaseUser $databaseUser, string $rootPassword): void
    {
        $user = $databaseUser->username;
        $pass = $databaseUser->password;
        $host = $databaseUser->host ?? 'localhost';

        $account = $this->mysqlString($user).'@'.$this->mysqlString($host);

        $statements = ["CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$this->mysqlString($pass)}"];

        $privilege = $databaseUser->permission === 'readonly' ? 'SELECT' : 'ALL PRIVILEGES';

        foreach ($databaseUser->databases as $dbName) {
            $statements[] = "GRANT {$privilege} ON {$this->mysqlIdentifier($dbName)}.* TO {$account}";
        }

        $statements[] = 'FLUSH PRIVILEGES';

        $this->runMysqlAsRoot($connection, $rootPassword, $statements);
    }

    private function createPostgresUser(SshConnection $connection, DatabaseUser $databaseUser): void
    {
        $user = $databaseUser->username;
        $pass = $databaseUser->password;

        $userIdent = '"'.str_replace('"', '""', $user).'"';
        $passEsc = str_replace("'", "''", $pass);

        $this->runPostgresAsSuperuser($connection, ['CREATE USER '.$userIdent.' WITH PASSWORD '."'{$passEsc}'"]);

        $this->grantPostgresPrivileges($connection, $databaseUser, $userIdent);
    }
}
