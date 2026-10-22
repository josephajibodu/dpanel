<?php

namespace App\Actions\Database;

use App\Jobs\CreateServerDatabaseJob;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Support\Str;
use RuntimeException;

class CreateServerDatabase
{
    use EscapesShell, QuotesMysql, RunsSqlPrivately;

    public function __construct(
        private SshService $sshService,
        private CreateDatabaseUser $createDatabaseUser,
        private UpdateDatabaseUser $updateDatabaseUser,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Server $server, array $input): ServerDatabase
    {
        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $serverDatabase = $server->databases()->create([
            'name' => $input['name'],
            'collation' => $input['collation'] ?? null,
            'charset' => $input['charset'] ?? null,
            'status' => 'pending',
        ]);

        CreateServerDatabaseJob::dispatch($serverDatabase);

        $this->ensureDatabaseUser($server, $serverDatabase, $input);

        return $serverDatabase;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function ensureDatabaseUser(Server $server, ServerDatabase $serverDatabase, array $input): void
    {
        $username = ! empty($input['db_user']) ? $input['db_user'] : config('server.user');
        $password = ! empty($input['db_password']) ? $input['db_password'] : Str::random(32);

        $existingUser = $server->databaseUsers()
            ->where('username', $username)
            ->first();

        if ($existingUser) {
            $databases = array_unique(array_merge($existingUser->databases ?? [], [$serverDatabase->name]));

            $this->updateDatabaseUser->update($existingUser, [
                'databases' => array_values($databases),
            ]);

            return;
        }

        $this->createDatabaseUser->create($server, [
            'username' => $username,
            'password' => $password,
            'databases' => [$serverDatabase->name],
            'permission' => 'readwrite',
            'host' => 'localhost',
        ]);
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
        $charset = $serverDatabase->charset ?? 'utf8mb4';
        $collation = $serverDatabase->collation ?? 'utf8mb4_unicode_ci';

        $connection = $this->sshService->connect($server);

        try {
            $databaseType = $server->database_type;

            if ($databaseType === 'mysql' || $databaseType === 'mariadb') {
                $this->createMysqlDatabase($connection, $name, $charset, $collation, $rootPassword);
            } elseif ($databaseType === 'postgresql') {
                $this->createPostgresDatabase($connection, $name);
            } else {
                throw new RuntimeException("Unsupported database type: {$databaseType}");
            }
        } finally {
            $connection->disconnect();
        }
    }

    private function createMysqlDatabase(
        SshConnection $connection,
        string $name,
        string $charset,
        string $collation,
        string $rootPassword,
    ): void {
        $this->runMysqlAsRoot($connection, $rootPassword, [
            "CREATE DATABASE IF NOT EXISTS {$this->mysqlIdentifier($name)} CHARACTER SET {$charset} COLLATE {$collation}",
        ]);
    }

    private function createPostgresDatabase(SshConnection $connection, string $name): void
    {
        $escapedName = str_replace('"', '""', $name);

        $this->runPostgresAsSuperuser($connection, ['CREATE DATABASE "'.$escapedName.'"']);
    }
}
