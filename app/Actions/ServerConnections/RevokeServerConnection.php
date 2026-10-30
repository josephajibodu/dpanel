<?php

namespace App\Actions\ServerConnections;

use App\Actions\Database\EscapesShell;
use App\Actions\Database\ManagesRemoteMysqlAccounts;
use App\Actions\Database\QuotesMysql;
use App\Actions\Database\RunsSqlPrivately;
use App\Enums\ServerConnectionStatus;
use App\Jobs\RevokeServerConnectionJob;
use App\Models\DatabaseUser;
use App\Models\ServerConnection;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;

/**
 * Closes the host's firewall to a client server again. The services keep
 * listening on the network (harmless with the ports closed), so other
 * connections and a later re-connect don't need a restart.
 */
class RevokeServerConnection
{
    use EscapesShell, ManagesRemoteMysqlAccounts, QuotesMysql, RunsSqlPrivately;

    public function __construct(
        private SshService $sshService,
    ) {}

    public function revoke(ServerConnection $serverConnection): void
    {
        $serverConnection->update(['status' => ServerConnectionStatus::Removing, 'error_message' => null]);

        RevokeServerConnectionJob::dispatch($serverConnection);
    }

    public function execute(ServerConnection $serverConnection): void
    {
        $host = $serverConnection->host;
        $ssh = $this->sshService->connect($host);

        try {
            foreach ($host->sharedServicePorts() as $port) {
                $ssh->exec("sudo ufw delete allow from {$serverConnection->client_address} to any port {$port} proto tcp || true");
            }

            if ($host->database_type === 'postgresql') {
                $hbaFile = trim($ssh->exec('sudo -u postgres psql -tAc "SHOW hba_file"'));
                $ssh->exec('sudo sed -i '.$this->escapeForShell("/# {$serverConnection->marker()}\$/d").' '.$this->escapeForShell($hbaFile));
                $ssh->exec('sudo systemctl reload postgresql');
            }

            $isLastConnection = ! $host->hostedConnections()->whereKeyNot($serverConnection->getKey())->exists();

            if ($isLastConnection && in_array($host->database_type, ['mysql', 'mariadb'], true)) {
                $this->dropRemoteMysqlAccounts($ssh, $serverConnection);
            }
        } finally {
            $ssh->disconnect();
        }

        $serverConnection->delete();
    }

    private function dropRemoteMysqlAccounts(SshConnection $ssh, ServerConnection $serverConnection): void
    {
        $host = $serverConnection->host;

        $statements = $host->databaseUsers()
            ->get()
            ->reject(fn (DatabaseUser $user): bool => ($user->host ?? 'localhost') === '%')
            ->map(fn (DatabaseUser $user): string => "DROP USER IF EXISTS {$this->remoteMysqlAccount($user)}")
            ->values()
            ->all();

        if ($statements === []) {
            return;
        }

        $statements[] = 'FLUSH PRIVILEGES';

        $this->runMysqlAsRoot($ssh, (string) $host->credential('database_password')?->value, $statements);
    }
}
