<?php

namespace App\Actions\Database;

use App\Services\Ssh\SshConnection;
use Closure;
use RuntimeException;

/**
 * Runs SQL as the database superuser without any secret appearing in a command.
 *
 * Every user on a server can see the full text of running commands (`ps`), so a
 * password passed as `-p…` or inside the SQL after `-e` leaks while it runs. The
 * credentials and the SQL are uploaded into a fresh private directory instead
 * (mktemp -d creates it readable only by the server user), and the commands only
 * reference those files. Classes using this trait must also use EscapesShell.
 */
trait RunsSqlPrivately
{
    /**
     * @param  array<int, string>  $statements
     */
    private function runMysqlAsRoot(SshConnection $connection, string $rootPassword, array $statements): void
    {
        $this->withPrivateDirectory($connection, function (string $directory) use ($connection, $rootPassword, $statements): void {
            $credentialsFile = "{$directory}/client.cnf";
            $sqlFile = "{$directory}/statements.sql";

            $connection->upload("[client]\nuser=root\npassword=\"".addcslashes($rootPassword, '"\\')."\"\n", $credentialsFile);
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
     * @param  Closure(string): void  $callback
     */
    private function withPrivateDirectory(SshConnection $connection, Closure $callback): void
    {
        $directory = trim($connection->exec('mktemp -d', 10));

        if (! str_starts_with($directory, '/tmp/')) {
            throw new RuntimeException("Could not create a private temporary directory (got: {$directory}).");
        }

        try {
            $callback($directory);
        } finally {
            $connection->exec('rm -rf '.$this->escapeForShell($directory), 10);
        }
    }

    /**
     * @param  array<int, string>  $statements
     */
    private function toSqlScript(array $statements): string
    {
        return implode(";\n", $statements).";\n";
    }
}
