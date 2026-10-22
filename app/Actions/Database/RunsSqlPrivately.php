<?php

namespace App\Actions\Database;

use App\Services\Ssh\SshConnection;

/**
 * Runs SQL as the database superuser without any secret appearing in a command:
 * the credentials and the SQL itself (which can carry user passwords) are
 * uploaded into a private directory and the commands only reference the files.
 * Classes using this trait must also use EscapesShell.
 */
trait RunsSqlPrivately
{
    use KeepsSecretsOffCommandLine;

    /**
     * @param  array<int, string>  $statements
     */
    private function runMysqlAsRoot(SshConnection $connection, string $rootPassword, array $statements): void
    {
        $this->withPrivateDirectory($connection, function (string $directory) use ($connection, $rootPassword, $statements): void {
            $credentialsFile = $this->uploadMysqlRootCredentials($connection, $directory, $rootPassword);
            $sqlFile = "{$directory}/statements.sql";

            $connection->upload($this->toSqlScript($statements), $sqlFile);

            $connection->exec(
                'sudo mysql --defaults-extra-file='.$this->escapeForShell($credentialsFile).' < '.$this->escapeForShell($sqlFile),
                60,
            );
        });
    }

    /**
     * Postgres needs no password here: `sudo -u postgres psql` signs in as the
     * local postgres system user over the server's own socket.
     *
     * @param  array<int, string>  $statements
     */
    private function runPostgresAsSuperuser(SshConnection $connection, array $statements, ?string $database = null): void
    {
        $this->withPrivateDirectory($connection, function (string $directory) use ($connection, $statements, $database): void {
            $sqlFile = "{$directory}/statements.sql";

            $connection->upload($this->toSqlScript($statements), $sqlFile);

            $databaseOption = $database === null ? '' : ' -d '.$this->escapeForShell($database);

            $connection->exec(
                'sudo -u postgres psql -v ON_ERROR_STOP=1'.$databaseOption.' < '.$this->escapeForShell($sqlFile),
                60,
            );
        });
    }

    /**
     * @param  array<int, string>  $statements
     */
    private function toSqlScript(array $statements): string
    {
        return implode(";\n", $statements).";\n";
    }
}
