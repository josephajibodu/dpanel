<?php

namespace App\Actions\ServerConnections;

use App\Actions\Database\EscapesShell;
use App\Actions\Database\ManagesRemoteMysqlAccounts;
use App\Actions\Database\QuotesMysql;
use App\Actions\Database\RunsSqlPrivately;
use App\Enums\ServerConnectionStatus;
use App\Jobs\AllowServerConnectionJob;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerConnection;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opens the host server's database and Redis to one client server: the
 * services listen on the network, and the host's firewall lets only the
 * client's address through on their ports.
 */
class AllowServerConnection
{
    use EscapesShell, ManagesRemoteMysqlAccounts, QuotesMysql, RunsSqlPrivately;

    public const MYSQL_NETWORK_CONFIG = '/etc/mysql/mysql.conf.d/zz-flitops-network.cnf';

    public const MARIADB_NETWORK_CONFIG = '/etc/mysql/mariadb.conf.d/99-flitops-network.cnf';

    public const REDIS_NETWORK_CONFIG = '/etc/redis/flitops.conf';

    public function __construct(
        private SshService $sshService,
    ) {}

    public function allow(Server $host, Server $client): ServerConnection
    {
        if (! $host->isReady() || ! $client->isReady()) {
            throw new RuntimeException('Both servers must be active and connected.');
        }

        $addresses = ServerConnection::addressesBetween($host, $client);

        $connection = $host->hostedConnections()->create([
            'client_server_id' => $client->id,
            'host_address' => $addresses['host'],
            'client_address' => $addresses['client'],
            'status' => ServerConnectionStatus::Pending,
        ]);

        AllowServerConnectionJob::dispatch($connection);

        return $connection;
    }

    public function execute(ServerConnection $serverConnection): void
    {
        $host = $serverConnection->host;
        $ssh = $this->sshService->connect($host);

        try {
            if ($host->hasDatabase()) {
                $this->openDatabase($ssh, $host, $serverConnection);
            }

            if ($host->sharesRedis()) {
                $this->openRedis($ssh, $host);
            }

            foreach ($host->sharedServicePorts() as $port) {
                $ssh->exec(
                    "sudo ufw allow from {$serverConnection->client_address} to any port {$port} proto tcp comment "
                    .$this->escapeForShell($serverConnection->marker())
                );
            }
        } finally {
            $ssh->disconnect();
        }
    }

    private function openDatabase(SshConnection $ssh, Server $host, ServerConnection $serverConnection): void
    {
        if ($host->database_type === 'postgresql') {
            $this->openPostgres($ssh, $serverConnection);

            return;
        }

        [$configPath, $unit] = $host->database_type === 'mariadb'
            ? [self::MARIADB_NETWORK_CONFIG, 'mariadb']
            : [self::MYSQL_NETWORK_CONFIG, 'mysql'];

        // Read after the package's own config, so this bind-address wins.
        // Restarting only the first time keeps later connections from
        // interrupting the database.
        $ssh->exec(
            "if ! sudo test -f {$configPath}; then "
            ."printf '[mysqld]\\nbind-address = 0.0.0.0\\n' | sudo tee {$configPath} > /dev/null && sudo systemctl restart {$unit}; fi",
            120,
        );

        $this->addRemoteMysqlAccounts($ssh, $host);
    }

    private function addRemoteMysqlAccounts(SshConnection $ssh, Server $host): void
    {
        $statements = $host->databaseUsers()
            ->get()
            ->flatMap(fn (DatabaseUser $user): array => $this->remoteMysqlAccountStatements($user))
            ->all();

        if ($statements === []) {
            return;
        }

        $statements[] = 'FLUSH PRIVILEGES';

        $this->runMysqlAsRoot($ssh, (string) $host->credential('database_password')?->value, $statements);
    }

    /**
     * Postgres listens on every address (set once, then restarted), and its
     * pg_hba.conf lets the client sign in with a password from its address.
     */
    private function openPostgres(SshConnection $ssh, ServerConnection $serverConnection): void
    {
        $listening = trim($ssh->exec('sudo -u postgres psql -tAc "SHOW listen_addresses"'));

        if ($listening !== '*') {
            $this->runPostgresAsSuperuser($ssh, ["ALTER SYSTEM SET listen_addresses = '*'"]);
            $ssh->exec('sudo systemctl restart postgresql', 120);
        }

        $hbaFile = trim($ssh->exec('sudo -u postgres psql -tAc "SHOW hba_file"'));
        $line = "host all all {$serverConnection->client_address}/32 scram-sha-256 # {$serverConnection->marker()}";
        $hbaEsc = $this->escapeForShell($hbaFile);

        $ssh->exec(
            'sudo grep -qF '.$this->escapeForShell($serverConnection->marker())." {$hbaEsc} || "
            .'echo '.$this->escapeForShell($line)." | sudo tee -a {$hbaEsc} > /dev/null"
        );
        $ssh->exec('sudo systemctl reload postgresql');
    }

    /**
     * Redis listens on every address with a password. The settings live in
     * their own file, included at the end of redis.conf so they win, and the
     * password file is only readable by root and Redis.
     */
    private function openRedis(SshConnection $ssh, Server $host): void
    {
        $password = $host->credential('redis_password')?->value;

        if ($password === null) {
            $password = Str::random(40);
            $host->credentials()->create(['type' => 'redis_password', 'value' => $password]);
        }

        $this->installAsRoot(
            $ssh,
            "# Managed by FlitOps\nbind 0.0.0.0\nprotected-mode yes\nrequirepass {$password}\n",
            self::REDIS_NETWORK_CONFIG,
            '0640',
            group: 'redis',
        );

        $include = 'include '.self::REDIS_NETWORK_CONFIG;
        $ssh->exec(
            'sudo grep -qxF '.$this->escapeForShell($include).' /etc/redis/redis.conf || '
            .'echo '.$this->escapeForShell($include).' | sudo tee -a /etc/redis/redis.conf > /dev/null'
        );
        $ssh->exec('sudo systemctl restart redis-server', 60);
    }
}
