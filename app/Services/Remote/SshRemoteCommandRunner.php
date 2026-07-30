<?php

namespace App\Services\Remote;

use App\Contracts\Remote\RemoteCommandRunner;
use App\Exceptions\SshCommandException;
use App\Services\Ssh\SshConnection;

class SshRemoteCommandRunner implements RemoteCommandRunner
{
    /**
     * @param  (\Closure(string): void)|null  $onOutput  Called once per line of stdout/stderr, in order, as it streams in.
     */
    public function __construct(
        private SshConnection $connection,
        private ?\Closure $onOutput = null,
    ) {}

    public function run(string $command, int $timeout = 60): string
    {
        return $this->execute($command, $timeout);
    }

    public function runQuietly(string $command, int $timeout = 60): void
    {
        $this->execute($command, $timeout);
    }

    /**
     * Run the command, streaming each output line to the onOutput callback while
     * accumulating it for the return value, then enforce the same throw-on-nonzero-exit
     * contract the buffering exec() call previously provided.
     */
    private function execute(string $command, int $timeout): string
    {
        $lines = [];

        $exitCode = $this->connection->execWithOutput(
            $command,
            function (string $line) use (&$lines): void {
                $lines[] = $line;

                if ($this->onOutput !== null) {
                    ($this->onOutput)($line);
                }
            },
            $timeout,
        );

        $output = implode("\n", $lines);

        if ($exitCode !== 0) {
            throw new SshCommandException(
                command: $command,
                exitCode: $exitCode,
                output: $output,
                stderr: $this->connection->lastStdError(),
            );
        }

        return $output;
    }
}
