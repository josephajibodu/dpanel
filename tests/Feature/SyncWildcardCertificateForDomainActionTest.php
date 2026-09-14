<?php

use App\Actions\Certificates\SyncWildcardCertificateForDomainAction;
use App\Models\Certificate;
use App\Services\Certificates\WildcardCertificateIssuer;
use App\Services\Ssh\SshConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

function syncWildcardCertificateAction(): SyncWildcardCertificateForDomainAction
{
    // None of these tests exercise a stale certificate, so the issuer is
    // never actually invoked — a real instance is inert here.
    return new SyncWildcardCertificateForDomainAction(new WildcardCertificateIssuer);
}

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('server.free_domain', 'flitops.test');

    $this->certDir = sys_get_temp_dir().'/sync-cert-'.bin2hex(random_bytes(4));
    @mkdir($this->certDir, 0700, true);

    $this->certContent = 'FAKE-CERT-'.bin2hex(random_bytes(16));
    $this->keyContent = 'FAKE-KEY-'.bin2hex(random_bytes(16));
    file_put_contents("{$this->certDir}/server.crt", $this->certContent);
    file_put_contents("{$this->certDir}/server.key", $this->keyContent);

    $this->cert = Certificate::factory()->create([
        'domain' => '*.flitops.test',
        'certificate_path' => "{$this->certDir}/server.crt",
        'private_key_path' => "{$this->certDir}/server.key",
    ]);
});

afterEach(function () {
    @unlink("{$this->certDir}/server.crt");
    @unlink("{$this->certDir}/server.key");
    @rmdir($this->certDir);
});

it('uploads cert + key for one (site, domain) and lands them at the ID-based paths', function () {
    $execCalls = [];
    $uploads = [];

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $cmd) use (&$execCalls) {
        $execCalls[] = $cmd;

        return ''; // sha256sum probe → empty → triggers upload
    });
    $connection->shouldReceive('upload')->andReturnUsing(function (string $content, string $path) use (&$uploads) {
        $uploads[$path] = $content;
    });

    $result = syncWildcardCertificateAction()->execute($connection, siteId: 42, domainId: 7);

    expect($result)->toBeTrue();

    // Two temp uploads (cert + key) into /tmp/wildcard_*.
    expect($uploads)->toHaveCount(2);
    foreach (array_keys($uploads) as $tempPath) {
        expect($tempPath)->toStartWith('/tmp/wildcard_');
    }

    // sudo install moves them to the Forge-style ID-based path with the right modes.
    $installs = collect($execCalls)->filter(fn ($c) => str_starts_with($c, 'sudo install '))->values();
    expect($installs)->toHaveCount(2);
    expect($installs->contains(fn ($c) => str_contains($c, '/etc/nginx/ssl/domains/42/7/server.crt') && str_contains($c, '-m 0644')))->toBeTrue();
    expect($installs->contains(fn ($c) => str_contains($c, '/etc/nginx/ssl/domains/42/7/server.key') && str_contains($c, '-m 0600')))->toBeTrue();

    // Action never reloads nginx — the caller (site provisioner) does that itself.
    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, 'reload nginx')))->toBeFalse();
});

it('returns false and does no work when the remote cert hash already matches', function () {
    $localHash = hash('sha256', $this->certContent);

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $cmd) use ($localHash) {
        return str_contains($cmd, 'sha256sum') ? $localHash."\n" : '';
    });
    $connection->shouldReceive('upload')->never();

    $result = syncWildcardCertificateAction()->execute($connection, siteId: 1, domainId: 1);

    expect($result)->toBeFalse();
});

it('returns false when no wildcard certificate exists yet', function () {
    $this->cert->delete();

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldNotReceive('exec');
    $connection->shouldNotReceive('upload');

    $result = syncWildcardCertificateAction()->execute($connection, siteId: 1, domainId: 1);

    expect($result)->toBeFalse();
});

it('throws when the wildcard cert files are missing on disk', function () {
    @unlink("{$this->certDir}/server.crt");

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturn('');
    $connection->shouldReceive('upload')->never();

    expect(fn () => syncWildcardCertificateAction()->execute($connection, siteId: 1, domainId: 1))
        ->toThrow(RuntimeException::class, 'missing on disk');
});

it('renews a certificate that is due for renewal before installing it', function () {
    $this->cert->update(['expires_at' => now()->addDays(10)]);

    $renewedCert = Certificate::factory()->make([
        'domain' => '*.flitops.test',
        'certificate_path' => "{$this->certDir}/server.crt",
        'private_key_path' => "{$this->certDir}/server.key",
        'expires_at' => now()->addDays(90),
    ]);

    $issuer = Mockery::mock(WildcardCertificateIssuer::class);
    $issuer->shouldReceive('issueOrRenew')->once()->with('*.flitops.test')->andReturn($renewedCert);

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturn('');
    $connection->shouldReceive('upload')->andReturnNull();

    $result = (new SyncWildcardCertificateForDomainAction($issuer))->execute($connection, siteId: 1, domainId: 1);

    expect($result)->toBeTrue();
});

it('falls back to the existing certificate when renewal fails but it is still valid', function () {
    $this->cert->update(['expires_at' => now()->addDays(10)]);

    $issuer = Mockery::mock(WildcardCertificateIssuer::class);
    $issuer->shouldReceive('issueOrRenew')->once()->andThrow(new RuntimeException('acme.sh unreachable'));

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturn('');
    $connection->shouldReceive('upload')->andReturnNull();

    $result = (new SyncWildcardCertificateForDomainAction($issuer))->execute($connection, siteId: 1, domainId: 1);

    expect($result)->toBeTrue();
});

it('throws instead of installing a certificate when renewal fails and the existing one has already expired', function () {
    $this->cert->update(['expires_at' => now()->subDay()]);

    $issuer = Mockery::mock(WildcardCertificateIssuer::class);
    $issuer->shouldReceive('issueOrRenew')->once()->andThrow(new RuntimeException('acme.sh unreachable'));

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldNotReceive('exec');
    $connection->shouldNotReceive('upload');

    expect(fn () => (new SyncWildcardCertificateForDomainAction($issuer))->execute($connection, siteId: 1, domainId: 1))
        ->toThrow(RuntimeException::class, 'already expired');
});
