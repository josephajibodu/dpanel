<?php

use App\Enums\SiteDomainType;
use App\Jobs\DeleteSiteDomainJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fakeSshForDeleteJob(Server $server, array &$execCalls = []): SshService
{
    $connection = \Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $cmd) use (&$execCalls) {
        $execCalls[] = $cmd;

        return 'nginx: configuration file /etc/nginx/nginx.conf syntax is ok';
    });
    $connection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($connection);

    return $sshService;
}

it('deletes the domain and runs nginx cleanup on the server', function () {
    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'to-delete.com',
        'is_primary' => false,
    ]);

    $this->app->instance(SshService::class, fakeSshForDeleteJob($server));

    $job = new DeleteSiteDomainJob($domain, $site);
    $job->handle(app(SshService::class));

    $this->assertDatabaseMissing('site_domains', ['id' => $domain->id]);
});

it('deletes a domain that has ssl enabled', function () {
    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'ssl-domain.com',
        'is_primary' => false,
        'ssl_enabled_at' => now(),
    ]);

    expect($domain->hasSsl())->toBeTrue();

    $this->app->instance(SshService::class, fakeSshForDeleteJob($server));

    $job = new DeleteSiteDomainJob($domain, $site);
    $job->handle(app(SshService::class));

    $this->assertDatabaseMissing('site_domains', ['id' => $domain->id]);
});

it('removes the certbot deploy hook so renewals stop touching the deleted domain', function () {
    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'ssl-domain.com',
        'is_primary' => false,
        'ssl_enabled_at' => now(),
    ]);

    $execCalls = [];
    $this->app->instance(SshService::class, fakeSshForDeleteJob($server, $execCalls));

    (new DeleteSiteDomainJob($domain, $site))->handle(app(SshService::class));

    expect($execCalls)->toContain("sudo rm -f /etc/letsencrypt/renewal-hooks/deploy/flitops-domain-{$domain->id}.sh");
});

it('deletes the certbot certificate for a custom domain', function () {
    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'ssl-domain.com',
        'is_primary' => false,
    ]);

    $execCalls = [];
    $this->app->instance(SshService::class, fakeSshForDeleteJob($server, $execCalls));

    (new DeleteSiteDomainJob($domain, $site))->handle(app(SshService::class));

    expect($execCalls)->toContain("sudo certbot delete --non-interactive --cert-name 'ssl-domain.com' 2>&1 || true");
});

it('leaves certbot alone for a system domain', function () {
    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->system()->create([
        'hostname' => 'system-delete.flitops.xyz',
        'is_primary' => false,
    ]);

    $execCalls = [];
    $this->app->instance(SshService::class, fakeSshForDeleteJob($server, $execCalls));

    (new DeleteSiteDomainJob($domain, $site))->handle(app(SshService::class));

    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, 'certbot')))->toBeFalse();
});

it('deletes the domain even when the server is missing', function () {
    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'orphan.com',
        'is_primary' => false,
    ]);

    // Build the job while the model graph still exists (constructor extracts paths),
    // then delete the server to simulate it being gone when the job actually runs.
    $job = new DeleteSiteDomainJob($domain, $site);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connect');
    $this->app->instance(SshService::class, $sshService);

    $server->delete();

    $job->handle(app(SshService::class));

    $this->assertDatabaseMissing('site_domains', ['id' => $domain->id]);
});
