<?php

namespace App\Services\Remote;

use App\Contracts\Remote\RemoteCommandRunner;
use App\Services\Ssh\SshConnection;

class SshRemoteCommandRunner implements RemoteCommandRunner
{
    public function __construct(
        private SshConnection $connection,
    ) {}

    public function run(string $command, int $timeout = 60): string
    {
        return $this->connection->exec($command, $timeout);
    }

    public function runQuietly(string $command, int $timeout = 60): void
    {
        // Reuse exec but ignore the output.
        $this->connection->exec($command, $timeout);
    }
}
