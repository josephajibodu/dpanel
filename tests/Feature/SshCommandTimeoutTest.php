<?php

use App\Models\Server;
use App\Services\Ssh\SshConnection;
use phpseclib3\Net\SSH2;

/**
 * An SSH2 stand-in that times out on the given commands the way phpseclib
 * does: exec() returns normally, the exit status still holds the previous
 * command's code, and the channel stays open, so the next exec() is refused
 * until reset() closes it.
 */
class FakeTimingOutSSH2 extends SSH2
{
    private bool $timedOut = false;

    private bool $channelOpen = false;

    /** @param array<int, string> $commandsThatTimeOut */
    public function __construct(private array $commandsThatTimeOut)
    {
        // Intentionally skip parent::__construct() — no network setup needed.
    }

    public function setTimeout($timeout): void
    {
        // no-op
    }

    public function exec($command, $callback = null)
    {
        if ($this->channelOpen) {
            throw new RuntimeException('Please close the channel (1) before trying to open it again');
        }

        $this->timedOut = in_array($command, $this->commandsThatTimeOut, true);
        $this->channelOpen = $this->timedOut;

        return $callback === null ? '' : true;
    }

    public function isTimeout()
    {
        return $this->timedOut;
    }

    public function reset($channel = null): void
    {
        $this->channelOpen = false;
    }

    public function getExitStatus()
    {
        return 0;
    }

    public function getStdError()
    {
        return '';
    }

    public function disconnect($reason = 11): void
    {
        // no-op
    }
}

/**
 * @param  array<int, string>  $commandsThatTimeOut
 */
function timingOutSshConnection(array $commandsThatTimeOut, ?Server $server = null): SshConnection
{
    return new SshConnection(
        new FakeTimingOutSSH2($commandsThatTimeOut),
        $server ?? Server::factory()->create(),
    );
}

it('fails a command that times out after an earlier command succeeded', function (Closure $run) {
    $connection = timingOutSshConnection(['apt-get install -y nginx']);
    $connection->exec('apt-get update');

    expect(fn () => $run($connection, 'apt-get install -y nginx', 5))
        ->toThrow(RuntimeException::class, 'SSH command timed out after 5 seconds: apt-get install -y nginx');
})->with([
    'buffered' => [fn (SshConnection $connection, string $command, int $timeout) => $connection->exec($command, $timeout)],
    'streamed' => [fn (SshConnection $connection, string $command, int $timeout) => $connection->execWithOutput($command, fn () => null, $timeout)],
]);

it('runs the next command after one times out', function () {
    $connection = timingOutSshConnection(['sleep 60']);

    try {
        $connection->execWithOutput('sleep 60', fn () => null, 5);
    } catch (RuntimeException) {
        // The timeout itself is covered above.
    }

    expect($connection->exec('rm -rf /tmp/tmp.private'))->toBe('');
});

it('removes the server passwords from the timeout error', function () {
    $server = Server::factory()->create();
    $server->credentials()->create(['type' => 'sudo_password', 'value' => 'super-secret-sudo']);
    $command = 'echo "artisan:super-secret-sudo" | chpasswd';
    $connection = timingOutSshConnection([$command], $server);

    expect(fn () => $connection->exec($command, 5))
        ->toThrow(RuntimeException::class, 'echo "artisan:[redacted]" | chpasswd');
});
