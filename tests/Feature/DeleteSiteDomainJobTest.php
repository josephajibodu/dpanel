<?php

use App\Enums\SiteDomainType;
use App\Jobs\DeleteSiteDomainJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fakeSshForDeleteJob(Server $server): SshService
{
    $connection = \Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturn('nginx: configuration file /etc/nginx/nginx.conf syntax is ok');
    $connection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($connection);

    return $sshService;
}

function fakeCloudflare(): CloudflareDnsService
{
    $cloudflare = \Mockery::mock(CloudflareDnsService::class);
    $cloudflare->shouldReceive('deleteRecord')->andReturn(null);

    return $cloudflare;
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
    $job->handle(app(SshService::class), fakeCloudflare());

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
    $job->handle(app(SshService::class), fakeCloudflare());

    $this->assertDatabaseMissing('site_domains', ['id' => $domain->id]);
});

it('deletes the cloudflare dns record when one exists', function () {
    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'cf-domain.com',
        'is_primary' => false,
        'cloudflare_dns_record_id' => 'abc123',
    ]);

    $cloudflare = \Mockery::mock(CloudflareDnsService::class);
    $cloudflare->shouldReceive('deleteRecord')->with('abc123')->once();

    $this->app->instance(SshService::class, fakeSshForDeleteJob($server));

    $job = new DeleteSiteDomainJob($domain, $site);
    $job->handle(app(SshService::class), $cloudflare);

    $this->assertDatabaseMissing('site_domains', ['id' => $domain->id]);
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

    $job->handle(app(SshService::class), fakeCloudflare());

    $this->assertDatabaseMissing('site_domains', ['id' => $domain->id]);
});
