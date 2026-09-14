<?php

use App\Enums\SiteDomainType;
use App\Jobs\IssueSslForDomainJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function generateSelfSignedTestCertificate(int $daysValid = 30): string
{
    $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'example.com'], $privateKey);
    $cert = openssl_csr_sign($csr, null, $privateKey, $daysValid);
    openssl_x509_export($cert, $certPem);

    return $certPem;
}

function fakeSshForIssueSslJob(Server $server, string $certPem): SshService
{
    $connection = \Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')
        ->with('which certbot 2>/dev/null || true')
        ->andReturn('/usr/bin/certbot');
    $connection->shouldReceive('exec')
        ->with(\Mockery::on(fn ($cmd) => str_contains($cmd, 'certbot certonly')))
        ->andReturn('Congratulations! Your certificate has been saved.');
    $connection->shouldReceive('exec')
        ->with(\Mockery::on(fn ($cmd) => str_contains($cmd, 'sudo cat') && str_contains($cmd, 'server.crt')))
        ->andReturn($certPem);
    $connection->shouldReceive('exec')->andReturn('');
    $connection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($connection);

    return $sshService;
}

it('sets ssl_enabled_at and ssl_expires_at after a successful run', function () {
    Queue::fake();

    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'example.com',
        'verified_at' => now(),
        'ssl_enabled_at' => null,
        'ssl_expires_at' => null,
    ]);

    $certPem = generateSelfSignedTestCertificate(30);

    $this->app->instance(SshService::class, fakeSshForIssueSslJob($server, $certPem));

    $job = new IssueSslForDomainJob($domain, $site);
    $job->handle(app(SshService::class));

    $domain->refresh();

    expect($domain->ssl_enabled_at)->not->toBeNull()
        ->and($domain->ssl_expires_at)->not->toBeNull()
        ->and($domain->ssl_expires_at->diffInDays(now(), true))->toBeLessThan(31)
        ->and($domain->ssl_expires_at->isFuture())->toBeTrue();
});

it('leaves ssl_expires_at unchanged when the certificate cannot be parsed', function () {
    Queue::fake();

    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $existingExpiry = now()->addDays(10);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'example.com',
        'verified_at' => now(),
        'ssl_enabled_at' => now()->subDays(80),
        'ssl_expires_at' => $existingExpiry,
    ]);

    $this->app->instance(SshService::class, fakeSshForIssueSslJob($server, 'not a valid certificate'));

    $job = new IssueSslForDomainJob($domain, $site);
    $job->handle(app(SshService::class));

    $domain->refresh();

    expect($domain->ssl_enabled_at)->not->toBeNull()
        ->and($domain->ssl_expires_at->toDateTimeString())->toBe($existingExpiry->toDateTimeString());
});
