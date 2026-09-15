<?php

namespace App\Actions\Database;

use App\Jobs\UpdateDatabaseUserJob;
use App\Models\DatabaseUser;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use RuntimeException;

class UpdateDatabaseUser
{
    use EscapesShell, GrantsPostgresPrivileges;

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
                $this->updatePostgresUser($connection, $databaseUser, $rootPassword);
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

        $userEsc = str_replace("'", "''", $user);
        $hostEsc = str_replace("'", "''", $host);
        $passEsc = str_replace("'", "''", $pass);

        $alterSql = "ALTER USER '{$userEsc}'@'{$hostEsc}' IDENTIFIED BY '{$passEsc}'";
        $cmd = 'sudo mysql -u root -p'.$this->escapeForShell($rootPassword).' -e '.$this->escapeForShell($alterSql);
        $connection->exec($cmd, 60);

        $revokeSql = "REVOKE ALL PRIVILEGES ON *.* FROM '{$userEsc}'@'{$hostEsc}'";
        $cmd = 'sudo mysql -u root -p'.$this->escapeForShell($rootPassword).' -e '.$this->escapeForShell($revokeSql);
        $connection->exec($cmd, 60);

        $privilege = $databaseUser->permission === 'readonly' ? 'SELECT' : 'ALL PRIVILEGES';

        foreach ($databaseUser->databases as $dbName) {
            $dbEsc = str_replace('`', '\\`', $dbName);
            $grantSql = "GRANT {$privilege} ON `{$dbEsc}`.* TO '{$userEsc}'@'{$hostEsc}'";
            $cmd = 'sudo mysql -u root -p'.$this->escapeForShell($rootPassword).' -e '.$this->escapeForShell($grantSql);
            $connection->exec($cmd, 60);
        }

        $cmd = 'sudo mysql -u root -p'.$this->escapeForShell($rootPassword).' -e "FLUSH PRIVILEGES;"';
        $connection->exec($cmd, 60);
    }

    private function updatePostgresUser(SshConnection $connection, DatabaseUser $databaseUser, string $postgresPassword): void
    {
        $user = $databaseUser->username;
        $pass = $databaseUser->password;

        $userIdent = '"'.str_replace('"', '""', $user).'"';
        $passEsc = str_replace("'", "''", $pass);

        $alterSql = 'ALTER USER '.$userIdent.' WITH PASSWORD '."'{$passEsc}'";
        $cmd = 'PGPASSWORD='.$this->escapeForShell($postgresPassword).' sudo -u postgres psql -c '.$this->escapeForShell($alterSql);
        $connection->exec($cmd, 60);

        // Revoke everything first so switching between readonly and readwrite
        // doesn't leave stale broader privileges from the previous grant.
        foreach ($databaseUser->databases as $dbName) {
            $dbIdent = '"'.str_replace('"', '""', $dbName).'"';
            $revokeSql = 'REVOKE ALL PRIVILEGES ON DATABASE '.$dbIdent.' FROM '.$userIdent;
            $cmd = 'PGPASSWORD='.$this->escapeForShell($postgresPassword).' sudo -u postgres psql -c '.$this->escapeForShell($revokeSql);
            $connection->exec($cmd, 60);

            $revokeTablesSql = 'REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM '.$userIdent;
            $cmd = 'PGPASSWORD='.$this->escapeForShell($postgresPassword).' sudo -u postgres psql -d '.$this->escapeForShell($dbName).' -c '.$this->escapeForShell($revokeTablesSql);
            $connection->exec($cmd, 60);
        }

        $this->grantPostgresPrivileges($connection, $databaseUser, $postgresPassword, $userIdent);
    }
}
