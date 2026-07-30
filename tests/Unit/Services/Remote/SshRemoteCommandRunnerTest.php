<?php

use App\Exceptions\SshCommandException;
use App\Services\Remote\SshRemoteCommandRunner;
use App\Services\Ssh\SshConnection;

/**
 * @param  list<string>  $lines
 */
function mockSshConnectionEmitting(array $lines, int $exitCode = 0, string $stderr = ''): SshConnection
{
    $connection = Mockery::mock(SshConnection::class);

    $connection->shouldReceive('execWithOutput')
        ->andReturnUsing(function (string $command, callable $onOutput) use ($lines, $exitCode) {
            foreach ($lines as $line) {
                $onOutput($line);
            }

            return $exitCode;
        });

    $connection->shouldReceive('lastStdError')->andReturn($stderr);

    return $connection;
}

it('streams each line to the onOutput callback in order', function () {
    $streamed = [];
    $runner = new SshRemoteCommandRunner(
        mockSshConnectionEmitting(['line one', 'line two']),
        function (string $line) use (&$streamed) {
            $streamed[] = $line;
        },
    );

    $runner->run('echo hi');

    expect($streamed)->toBe(['line one', 'line two']);
});

it('accumulates streamed lines into the run() return value', function () {
    $runner = new SshRemoteCommandRunner(mockSshConnectionEmitting(['first', 'second']));

    expect($runner->run('echo hi'))->toBe("first\nsecond");
});

it('still streams output for runQuietly() even though it returns nothing', function () {
    $streamed = [];
    $runner = new SshRemoteCommandRunner(
        mockSshConnectionEmitting(['apt output']),
        function (string $line) use (&$streamed) {
            $streamed[] = $line;
        },
    );

    $runner->runQuietly('apt-get install -y nginx');

    expect($streamed)->toBe(['apt output']);
});

it('throws with exit code, output, and stderr when the command fails', function () {
    $runner = new SshRemoteCommandRunner(
        mockSshConnectionEmitting(['some output'], exitCode: 1, stderr: 'boom'),
    );

    try {
        $runner->run('false');
        $this->fail('Expected SshCommandException to be thrown.');
    } catch (SshCommandException $e) {
        expect($e->exitCode)->toBe(1)
            ->and($e->output)->toBe('some output')
            ->and($e->stderr)->toBe('boom');
    }
});

it('works without an onOutput callback', function () {
    $runner = new SshRemoteCommandRunner(mockSshConnectionEmitting(['ok']));

    expect($runner->run('echo ok'))->toBe('ok');
});
