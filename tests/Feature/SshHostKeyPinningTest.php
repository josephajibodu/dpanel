<?php

use App\Exceptions\SshConnectionException;
use App\Exceptions\SshHostKeyMismatchException;
use App\Models\Backup;
use App\Models\Server;
use App\Services\Ssh\KeyGenerator;
use App\Services\Ssh\SshRetryHandler;
use App\Services\Ssh\SshService;
use App\Support\BackupFailureMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

uses(RefreshDatabase::class);

/**
 * Stands in for the network side of phpseclib: no socket is opened, the
 * "server" presents whatever host key the test hands it, and every login,
 * command and upload is recorded so tests can assert none happened.
 */
trait FakesSshServer
{
    public int $loginCalls = 0;

    /** @var array<int, string> */
    public array $commands = [];

    /** @var array<int, string> */
    public array $uploads = [];

    public bool $disconnected = false;

    public function __construct(
        private string|false $hostKey,
        private bool $loginSucceeds = true,
    ) {
        // Intentionally skip parent::__construct() — no network setup needed.
    }

    public function getServerPublicHostKey()
    {
        return $this->hostKey;
    }

    public function setKeepAlive($interval) {}

    public function setTimeout($timeout) {}

    public function login($username, ...$args)
    {
        $this->loginCalls++;

        return $this->loginSucceeds;
    }

    public function getErrors()
    {
        return [];
    }

    public function exec($command, $callback = null)
    {
        $this->commands[] = $command;

        return "ok\n";
    }

    public function getExitStatus()
    {
        return 0;
    }

    public function disconnect()
    {
        $this->disconnected = true;
    }
}

class FakeHostKeySSH2 extends SSH2
{
    use FakesSshServer;
}

class FakeHostKeySFTP extends SFTP
{
    use FakesSshServer;

    public function put($remote_file, $data, $mode = self::SOURCE_STRING, $start = -1, $local_start = -1, $progressCallback = null)
    {
        $this->uploads[] = $remote_file;

        return true;
    }
}

/**
 * A real Ed25519 host key, as phpseclib's getServerPublicHostKey() returns it,
 * plus the fingerprint `ssh-keygen -lf` would print for it.
 *
 * @return array{hostKey: string, fingerprint: string}
 */
function fakeHostKey(): array
{
    $publicKey = EC::createKey('Ed25519')->getPublicKey();
    [$type, $encoded] = explode(' ', $publicKey->toString('OpenSSH'));

    return [
        'hostKey' => "{$type} {$encoded}",
        'fingerprint' => 'SHA256:'.$publicKey->getFingerprint('sha256'),
    ];
}

/**
 * Make every SSH2 the app opens be the given fake, counting how many it opens.
 */
function fakeSshServer(FakeHostKeySSH2 $ssh): object
{
    $opened = new class
    {
        public int $count = 0;
    };

    app()->bind(SSH2::class, function () use ($ssh, $opened) {
        $opened->count++;

        return $ssh;
    });

    return $opened;
}

beforeEach(function () {
    $this->key = fakeHostKey();
    $this->server = Server::factory()->active()->create();
    $this->server->credentials()->create([
        'type' => 'private_key',
        'value' => app(KeyGenerator::class)->generate()->privateKey,
    ]);
});

it('records the host key on the first successful connection', function () {
    fakeSshServer(new FakeHostKeySSH2($this->key['hostKey']));

    app(SshService::class)->connectAsRoot($this->server);

    expect($this->server->fresh())
        ->host_key_type->toBe('ssh-ed25519')
        ->host_key_fingerprint->toBe($this->key['fingerprint']);
});

it('pins an existing server that has no stored key instead of rejecting it', function () {
    expect($this->server->hasPinnedHostKey())->toBeFalse();

    $ssh = new FakeHostKeySSH2($this->key['hostKey']);
    fakeSshServer($ssh);

    $connection = app(SshService::class)->connect($this->server);
    $connection->exec('uptime');

    expect($ssh->commands)->toBe(['uptime'])
        ->and($this->server->fresh()->host_key_fingerprint)->toBe($this->key['fingerprint']);
});

it('records an RSA key by its key type, whichever signature algorithm was negotiated', function () {
    $publicKey = RSA::createKey(2048)->getPublicKey();
    [, $encoded] = explode(' ', $publicKey->toString('OpenSSH'));
    fakeSshServer(new FakeHostKeySSH2("rsa-sha2-512 {$encoded}"));

    app(SshService::class)->connect($this->server);

    expect($this->server->fresh())
        ->host_key_type->toBe('ssh-rsa')
        ->host_key_fingerprint->toBe('SHA256:'.$publicKey->getFingerprint('sha256'));
});

it('does not record the host key when the login fails', function () {
    fakeSshServer(new FakeHostKeySSH2($this->key['hostKey'], loginSucceeds: false));

    expect(fn () => app(SshService::class)->connect($this->server))
        ->toThrow(SshConnectionException::class);

    expect($this->server->fresh()->hasPinnedHostKey())->toBeFalse();
});

it('connects when the server presents the recorded key', function () {
    $this->server->pinHostKey('ssh-ed25519', $this->key['fingerprint']);
    $ssh = new FakeHostKeySSH2($this->key['hostKey']);
    fakeSshServer($ssh);

    app(SshService::class)->connect($this->server)->exec('uptime');

    expect($ssh->loginCalls)->toBe(1)
        ->and($ssh->commands)->toBe(['uptime'])
        ->and($this->server->fresh()->host_key_fingerprint)->toBe($this->key['fingerprint']);
});

it('refuses a server whose key has changed, before logging in or running anything', function () {
    $this->server->pinHostKey('ssh-ed25519', $this->key['fingerprint']);
    $ssh = new FakeHostKeySSH2(fakeHostKey()['hostKey']);
    fakeSshServer($ssh);

    expect(fn () => app(SshService::class)->connect($this->server))
        ->toThrow(SshHostKeyMismatchException::class, "This server's identity has changed");

    expect($ssh->loginCalls)->toBe(0)
        ->and($ssh->commands)->toBe([])
        ->and($ssh->disconnected)->toBeTrue()
        ->and($this->server->fresh()->host_key_fingerprint)->toBe($this->key['fingerprint']);
});

it('tells the user how to reset the key in the mismatch message', function () {
    $this->server->pinHostKey('ssh-ed25519', $this->key['fingerprint']);
    fakeSshServer(new FakeHostKeySSH2(fakeHostKey()['hostKey']));

    expect(fn () => app(SshService::class)->connect($this->server))
        ->toThrow(SshHostKeyMismatchException::class, "php artisan flitops:server:forget-host-key {$this->server->id}");
});

it('does not retry a changed key as if it were a network blip', function () {
    $this->server->pinHostKey('ssh-ed25519', $this->key['fingerprint']);
    $ssh = new FakeHostKeySSH2(fakeHostKey()['hostKey']);
    $opened = fakeSshServer($ssh);

    expect(fn () => app(SshRetryHandler::class)->waitForSsh($this->server, 'root', maxAttempts: 5, initialDelay: 0))
        ->toThrow(SshHostKeyMismatchException::class);

    expect($opened->count)->toBe(1)
        ->and($ssh->commands)->toBe([]);
});

it('checks the host key on the separate SFTP connection used for uploads', function () {
    $this->server->pinHostKey('ssh-ed25519', $this->key['fingerprint']);
    fakeSshServer(new FakeHostKeySSH2($this->key['hostKey']));
    $sftp = new FakeHostKeySFTP(fakeHostKey()['hostKey']);
    app()->bind(SFTP::class, fn () => $sftp);

    $connection = app(SshService::class)->connect($this->server);

    expect(fn () => $connection->upload('APP_KEY=secret', '/home/flitops/site/.env'))
        ->toThrow(SshHostKeyMismatchException::class);

    expect($sftp->loginCalls)->toBe(0)
        ->and($sftp->uploads)->toBe([]);
});

it('uploads over SFTP when the server presents the recorded key', function () {
    $this->server->pinHostKey('ssh-ed25519', $this->key['fingerprint']);
    fakeSshServer(new FakeHostKeySSH2($this->key['hostKey']));
    $sftp = new FakeHostKeySFTP($this->key['hostKey']);
    app()->bind(SFTP::class, fn () => $sftp);

    app(SshService::class)->connect($this->server)->upload('contents', '/tmp/file');

    expect($sftp->uploads)->toBe(['/tmp/file']);
});

it('stops when the server presents no verifiable host key', function () {
    $ssh = new FakeHostKeySSH2(false);
    fakeSshServer($ssh);

    expect(fn () => app(SshService::class)->connect($this->server))
        ->toThrow(SshConnectionException::class, "Couldn't verify the server's identity");

    expect($ssh->loginCalls)->toBe(0)
        ->and($this->server->fresh()->hasPinnedHostKey())->toBeFalse();
});

it('shows the mismatch message on a failed backup instead of a generic connection error', function () {
    $backup = Backup::factory()->create();
    $exception = new SshHostKeyMismatchException($this->server, 'SHA256:other');

    expect(BackupFailureMessage::for($exception, $backup))->toBe($exception->getMessage());
});

// --- flitops:server:forget-host-key ---

it('forgets the key so the next connection records the new one', function () {
    $this->server->pinHostKey('ssh-ed25519', $this->key['fingerprint']);
    $rebuiltKey = fakeHostKey();

    $this->artisan('flitops:server:forget-host-key', ['server' => $this->server->id])
        ->expectsConfirmation('Forget this host key?', 'yes')
        ->assertSuccessful();

    expect($this->server->fresh()->hasPinnedHostKey())->toBeFalse();

    fakeSshServer(new FakeHostKeySSH2($rebuiltKey['hostKey']));
    app(SshService::class)->connect($this->server->fresh());

    expect($this->server->fresh()->host_key_fingerprint)->toBe($rebuiltKey['fingerprint']);
});

it('keeps the key when the reset is not confirmed', function () {
    $this->server->pinHostKey('ssh-ed25519', $this->key['fingerprint']);

    $this->artisan('flitops:server:forget-host-key', ['server' => $this->server->id])
        ->expectsConfirmation('Forget this host key?', 'no')
        ->assertFailed();

    expect($this->server->fresh()->host_key_fingerprint)->toBe($this->key['fingerprint']);
});

it('forgets the key without prompting when forced', function () {
    $this->server->pinHostKey('ssh-ed25519', $this->key['fingerprint']);

    $this->artisan('flitops:server:forget-host-key', ['server' => $this->server->id, '--force' => true])
        ->assertSuccessful();

    expect($this->server->fresh()->hasPinnedHostKey())->toBeFalse();
});

it('fails for an unknown server', function () {
    $this->artisan('flitops:server:forget-host-key', ['server' => 'missing'])
        ->assertFailed();
});

it('does nothing for a server with no recorded key', function () {
    $this->artisan('flitops:server:forget-host-key', ['server' => $this->server->id])
        ->expectsOutputToContain('has no recorded host key yet')
        ->assertSuccessful();
});
