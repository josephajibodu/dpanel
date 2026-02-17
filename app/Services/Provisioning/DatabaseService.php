<?php

namespace App\Services\Provisioning;

class DatabaseService
{
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

        $password = $context->databasePassword;

        $context->runner->runQuietly(
            "sudo mysql -e \"ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '{$password}';\" || true",
            120
        );

        $context->runner->runQuietly('sudo mysql -e "FLUSH PRIVILEGES;" || true', 120);
    }

    private function installPostgres(ProvisioningContext $context): void
    {
        $context->packages->ensureInstalled('postgresql');
        $context->packages->ensureInstalled('postgresql-contrib');

        $context->services->enable('postgresql');
        $context->services->start('postgresql');

        $password = $context->databasePassword;

        $context->runner->runQuietly(
            "sudo -u postgres psql -c \"ALTER USER postgres PASSWORD '{$password}';\"",
            120
        );
    }

    private function installMariadb(ProvisioningContext $context): void
    {
        $context->packages->ensureInstalled('mariadb-server');

        $context->services->enable('mariadb');
        $context->services->start('mariadb');

        $password = $context->databasePassword;

        $context->runner->runQuietly(
            "sudo mysql -e \"ALTER USER 'root'@'localhost' IDENTIFIED BY '{$password}';\" || true",
            120
        );

        $context->runner->runQuietly('sudo mysql -e "FLUSH PRIVILEGES;" || true', 120);
    }
}
