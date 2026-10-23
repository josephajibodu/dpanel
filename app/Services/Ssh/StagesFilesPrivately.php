<?php

namespace App\Services\Ssh;

use Closure;
use RuntimeException;

/**
 * /tmp is shared by every user on a server, including the low-privilege user
 * each isolated site runs as. A file staged there under a name another user
 * can guess or list could be pre-created, replaced or read before root moves
 * it into place. Files are staged in a fresh private directory instead
 * (mktemp -d creates it with a random name, readable only by the server user).
 * Classes using this trait must also use EscapesShell.
 */
trait StagesFilesPrivately
{
    /**
     * Run the callback with a private temporary directory that is removed
     * afterwards, even when the callback throws.
     *
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
     * Write content to a system path as a root-owned file with the given mode,
     * staged through a private directory. `install` sets the owner and mode
     * and moves the file into place in one step.
     */
    private function installAsRoot(SshConnection $connection, string $content, string $destination, string $mode = '644', int $timeout = 30): void
    {
        $this->withPrivateDirectory($connection, function (string $directory) use ($connection, $content, $destination, $mode, $timeout): void {
            $staged = "{$directory}/staged";

            $connection->upload($content, $staged);
            $connection->sudo(
                'install -o root -g root -m '.$this->escapeForShell($mode).' '.$this->escapeForShell($staged).' '.$this->escapeForShell($destination),
                $timeout,
            );
        });
    }
}
