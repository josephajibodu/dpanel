<?php

namespace App\Contracts\Remote;

interface RemoteCommandRunner
{
    /**
     * Run a command on the remote server and return its stdout output.
     *
     * Implementations SHOULD throw an exception if the command exits
     * with a non-zero status code.
     */
    public function run(string $command, int $timeout = 60): string;

    /**
     * Run a command on the remote server where the output is not needed.
     *
     * This is typically used for idempotent operations such as starting
     * or stopping services. Implementations MAY still throw on failure.
     */
    public function runQuietly(string $command, int $timeout = 60): void;
}
