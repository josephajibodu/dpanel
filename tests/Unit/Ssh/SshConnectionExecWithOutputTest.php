<?php

use App\Models\Server;
use App\Services\Ssh\SshConnection;
use phpseclib3\Net\SSH2;

afterEach(function () {
    \Mockery::close();
});

/**
 * Build a SshConnection backed by a mocked SSH2 that, when exec() is called,
 * fires $chunks at the supplied callback in order. Mirrors phpseclib's real
 * behavior of delivering output in TCP/SSH-packet-aligned chunks rather than
 * line-aligned ones.
 *
 * @param  array<int, string>  $chunks
 */
function fakeSshConnection(array $chunks, int $exitStatus = 0): SshConnection
{
    $ssh = \Mockery::mock(SSH2::class);
    $ssh->shouldReceive('setTimeout')->andReturnNull();
    $ssh->shouldReceive('exec')->andReturnUsing(function ($command, $callback) use ($chunks) {
        foreach ($chunks as $chunk) {
            $callback($chunk);
        }

        return '';
    });
    $ssh->shouldReceive('getExitStatus')->andReturn($exitStatus);

    $server = new Server;

    return new SshConnection($ssh, $server, 'artisan');
}

describe('SshConnection::execWithOutput', function () {
    it('emits one callback per complete line when chunks align with line boundaries', function () {
        $connection = fakeSshConnection([
            "first line\n",
            "second line\n",
            "third line\n",
        ]);

        $lines = [];
        $exitCode = $connection->execWithOutput('echo hi', function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        expect($lines)->toBe(['first line', 'second line', 'third line']);
        expect($exitCode)->toBe(0);
    });

    it('reassembles a single line that arrives split across multiple chunks', function () {
        // A long composer-style line delivered in pieces by phpseclib.
        $connection = fakeSshConnection([
            '    - symfony/clock v8.0.8 requires php >=8.4 ',
            '-> your php version (8.3.30) does ',
            "not satisfy that requirement.\n",
        ]);

        $lines = [];
        $connection->execWithOutput('composer install', function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        expect($lines)->toBe([
            '    - symfony/clock v8.0.8 requires php >=8.4 -> your php version (8.3.30) does not satisfy that requirement.',
        ]);
    });

    it('emits multiple lines from a single chunk that contains several newlines', function () {
        $connection = fakeSshConnection([
            "line one\nline two\nline three\n",
        ]);

        $lines = [];
        $connection->execWithOutput('cat foo', function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        expect($lines)->toBe(['line one', 'line two', 'line three']);
    });

    it('preserves empty lines so visual blank-line separators survive', function () {
        $connection = fakeSshConnection([
            "Installing dependencies...\n\nDone.\n",
        ]);

        $lines = [];
        $connection->execWithOutput('composer install', function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        expect($lines)->toBe(['Installing dependencies...', '', 'Done.']);
    });

    it('strips trailing carriage returns so CRLF output renders cleanly', function () {
        $connection = fakeSshConnection([
            "windows-style line\r\nplain line\n",
        ]);

        $lines = [];
        $connection->execWithOutput('cat foo', function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        expect($lines)->toBe(['windows-style line', 'plain line']);
    });

    it('flushes a trailing partial line that has no terminating newline', function () {
        $connection = fakeSshConnection([
            "first complete line\nno trailing newline here",
        ]);

        $lines = [];
        $connection->execWithOutput('printf foo', function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        expect($lines)->toBe(['first complete line', 'no trailing newline here']);
    });

    it('handles chunks split mid-newline-pair without inventing extra lines', function () {
        $connection = fakeSshConnection([
            "alpha\r",
            "\nbeta\n",
        ]);

        $lines = [];
        $connection->execWithOutput('echo', function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        expect($lines)->toBe(['alpha', 'beta']);
    });

    it('returns the SSH exit status reported by the underlying connection', function () {
        $connection = fakeSshConnection(["ok\n"], exitStatus: 7);

        $exitCode = $connection->execWithOutput('false', fn () => null);

        expect($exitCode)->toBe(7);
    });
});
