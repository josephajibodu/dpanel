<?php

namespace App\Services\Provisioning;

use App\Actions\Database\EscapesShell;
use App\Actions\Database\QuotesMysql;
use RuntimeException;

class DatabaseService
{
    use EscapesShell;
    use QuotesMysql;

    public function installForServer(ProvisioningContext $context): void
    {
        $databaseType = $context->server->database_type;

        if ($databaseType === 'mysql') {
            $this->installMysql($context);
        } elseif ($databaseType === 'postgresql') {
            $this->installPostgres($context);
        } elseif ($databaseType === 'mariadb') {
            $this->installMariadb($context);
        }
    }

    private function installMysql(ProvisioningContext $context): void
    {
        $context->packages->ensureInstalled('mysql-server');

        $context->services->enable('mysql');
        $context->services->start('mysql');

        $password = $this->mysqlString($context->databasePassword);

        $this->runSqlPrivately(
            $context,
            'sudo mysql',
            "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY {$password};\nFLUSH PRIVILEGES;\n",
            ignoreFailure: true,
        );
    }

    private function installPostgres(ProvisioningContext $context): void
    {
        $context->packages->ensureInstalled('postgresql');
        $context->packages->ensureInstalled('postgresql-contrib');

        $context->services->enable('postgresql');
        $context->services->start('postgresql');

        $password = str_replace("'", "''", $context->databasePassword);

        $this->runSqlPrivately(
            $context,
            'sudo -u postgres psql -v ON_ERROR_STOP=1',
            "ALTER USER postgres PASSWORD '{$password}';\n",
        );
    }

    private function installMariadb(ProvisioningContext $context): void
    {
        $context->packages->ensureInstalled('mariadb-server');

        $context->services->enable('mariadb');
        $context->services->start('mariadb');

        $password = $this->mysqlString($context->databasePassword);

        $this->runSqlPrivately(
            $context,
            'sudo mysql',
            "ALTER USER 'root'@'localhost' IDENTIFIED BY {$password};\nFLUSH PRIVILEGES;\n",
            ignoreFailure: true,
        );
    }

    /**
     * Any user on the server can read running commands (`ps`), so the SQL that
     * sets the root password is uploaded into a private directory (mktemp -d is
     * readable only by the server user) and fed to the client from that file.
     * The directory is removed afterwards, even when the command fails.
     * ignoreFailure keeps re-runs working once the root password is already set.
     */
    private function runSqlPrivately(ProvisioningContext $context, string $client, string $sql, bool $ignoreFailure = false): void
    {
        $directory = trim($context->runner->run('mktemp -d', 10));

        if (! str_starts_with($directory, '/tmp/')) {
            throw new RuntimeException("Could not create a private temporary directory (got: {$directory}).");
        }

        try {
            $sqlFile = "{$directory}/statements.sql";

            $context->files->put($sqlFile, $sql);

            $command = "{$client} < {$this->escapeForShell($sqlFile)}";

            $context->runner->runQuietly($ignoreFailure ? "{$command} || true" : $command, 120);
        } finally {
            $context->runner->runQuietly('rm -rf '.$this->escapeForShell($directory), 10);
        }
    }
}
