<?php

namespace App\Actions\Database;

use App\Jobs\UpdateDatabaseUserJob;
use App\Models\DatabaseUser;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use RuntimeException;

class UpdateDatabaseUser
{
    use EscapesShell, GrantsPostgresPrivileges, QuotesMysql, RunsSqlPrivately;

    public function __construct(
        private SshService $sshService
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(DatabaseUser $databaseUser, array $input): DatabaseUser
    {
        $data = [];
        if (array_key_exists('password', $input) && $input['password'] !== null && $input['password'] !== '') {
            $data['password'] = $input['password'];
        }
        if (array_key_exists('databases', $input)) {
            $data['databases'] = $input['databases'];
        }
        if (array_key_exists('permission', $input)) {
            $data['permission'] = $input['permission'];
        }
        if (array_key_exists('host', $input)) {
            $data['host'] = $input['host'];
        }

        $databaseUser->update($data);

        UpdateDatabaseUserJob::dispatch($databaseUser->fresh());

        return $databaseUser->fresh();
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
                $this->updateMysqlUser($connection, $databaseUser, $rootPassword);
            } elseif ($databaseType === 'postgresql') {
                $this->updatePostgresUser($connection, $databaseUser);
            } else {
                throw new RuntimeException("Unsupported database type: {$databaseType}");
            }
        } finally {
            $connection->disconnect();
        }
    }

    private function updateMysqlUser(SshConnection $connection, DatabaseUser $databaseUser, string $rootPassword): void
    {
        $user = $databaseUser->username;
        $pass = $databaseUser->password;
        $host = $databaseUser->host ?? 'localhost';

        $account = $this->mysqlString($user).'@'.$this->mysqlString($host);

        $statements = [
            "ALTER USER {$account} IDENTIFIED BY {$this->mysqlString($pass)}",
            "REVOKE ALL PRIVILEGES ON *.* FROM {$account}",
        ];

        $privilege = $databaseUser->permission === 'readonly' ? 'SELECT' : 'ALL PRIVILEGES';

        foreach ($databaseUser->databases as $dbName) {
            $statements[] = "GRANT {$privilege} ON {$this->mysqlIdentifier($dbName)}.* TO {$account}";
        }

        $statements[] = 'FLUSH PRIVILEGES';

        $this->runMysqlAsRoot($connection, $rootPassword, $statements);
    }

    private function updatePostgresUser(SshConnection $connection, DatabaseUser $databaseUser): void
    {
        $user = $databaseUser->username;
        $pass = $databaseUser->password;

        $userIdent = '"'.str_replace('"', '""', $user).'"';
        $passEsc = str_replace("'", "''", $pass);

        $this->runPostgresAsSuperuser($connection, ['ALTER USER '.$userIdent.' WITH PASSWORD '."'{$passEsc}'"]);

        // Revoke everything first so switching between readonly and readwrite
        // doesn't leave stale broader privileges from the previous grant.
        foreach ($databaseUser->databases as $dbName) {
            $dbIdent = '"'.str_replace('"', '""', $dbName).'"';
            $this->runPostgresAsSuperuser($connection, ['REVOKE ALL PRIVILEGES ON DATABASE '.$dbIdent.' FROM '.$userIdent]);
            $this->runPostgresAsSuperuser($connection, ['REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM '.$userIdent], $dbName);
        }

        $this->grantPostgresPrivileges($connection, $databaseUser, $userIdent);
    }
}
