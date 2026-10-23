<?php

namespace App\Actions\Database;

use App\Contracts\StorageProviderContract;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\StagesFilesPrivately;

/**
 * Every user on a server can see the full text of running commands (`ps`), so
 * a password or key typed into a command leaks while it runs. Secrets are
 * uploaded into a fresh private directory instead (mktemp -d creates it
 * readable only by the server user), and commands only reference those files.
 * Classes using this trait must also use EscapesShell.
 */
trait KeepsSecretsOffCommandLine
{
    use StagesFilesPrivately;

    /**
     * Upload a MySQL/MariaDB client option file for root and return its path,
     * to pass as the first option: `--defaults-extra-file=<path>`.
     */
    private function uploadMysqlRootCredentials(SshConnection $connection, string $directory, string $rootPassword): string
    {
        $path = "{$directory}/client.cnf";

        $connection->upload("[client]\nuser=root\npassword=\"".addcslashes($rootPassword, '"\\')."\"\n", $path);

        return $path;
    }

    /**
     * Upload the storage account's AWS CLI credentials file and return the
     * shell prefix that points the `aws` command at it.
     */
    private function uploadStorageCredentials(SshConnection $connection, string $directory, StorageProviderContract $driver): string
    {
        $path = "{$directory}/aws-credentials";

        $connection->upload($driver->awsCredentialsFile(), $path);

        return 'AWS_SHARED_CREDENTIALS_FILE='.$this->escapeForShell($path);
    }
}
