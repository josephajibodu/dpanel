<?php

namespace App\Actions\Database;

use App\Jobs\CreateServerDatabaseJob;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use RuntimeException;

class CreateServerDatabase
{
    use EscapesShell;

    public function __construct(
        private SshService $sshService
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

        return $serverDatabase;
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
                $this->createPostgresDatabase($connection, $name, $rootPassword);
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
        $escapedName = str_replace('`', '\\`', $name);
        $sql = "CREATE DATABASE IF NOT EXISTS `{$escapedName}` CHARACTER SET {$charset} COLLATE {$collation}";
        $cmd = 'sudo mysql -u root -p'.$this->escapeForShell($rootPassword).' -e '.$this->escapeForShell($sql);
        $connection->exec($cmd, 60);
    }

    private function createPostgresDatabase(
        SshConnection $connection,
        string $name,
        string $postgresPassword,
    ): void {
        $escapedName = str_replace('"', '""', $name);
        $sql = 'CREATE DATABASE "'.$escapedName.'"';
        $cmd = 'PGPASSWORD='.$this->escapeForShell($postgresPassword).' sudo -u postgres psql -c '.$this->escapeForShell($sql);
        $connection->exec($cmd, 60);
    }
}
