<?php

use App\Actions\Sites\CleanupSiteExternalResourcesAction;
use App\Enums\SiteDomainType;
use App\Jobs\DeleteSiteJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fakeSshForDeleteSiteJob(Server $server, array &$execCalls): SshService
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $cmd) use (&$execCalls) {
        $execCalls[] = $cmd;

        return '';
    });
    $connection->shouldReceive('disconnect')->andReturn(null);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($connection);

    return $sshService;
}

it('removes the SSL certificate directory for every domain on the site, at the correct per-domain path', function () {
    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'source_control_account_id' => null,
    ]);
    $extraDomain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'extra.example.com',
        'is_primary' => false,
    ]);
    $primaryDomain = $site->domains()->where('is_primary', true)->firstOrFail();

    $execCalls = [];
    $this->app->instance(SshService::class, fakeSshForDeleteSiteJob($server, $execCalls));

    $job = new DeleteSiteJob($site);
    $job->handle(app(SshService::class), app(CleanupSiteExternalResourcesAction::class));

    $removals = collect($execCalls)->filter(fn ($c) => str_starts_with($c, 'sudo rm -rf /etc/nginx/ssl/domains/'));

    expect($removals)->toHaveCount(2)
        ->and($removals->contains(fn ($c) => $c === "sudo rm -rf /etc/nginx/ssl/domains/{$site->id}/{$primaryDomain->id}"))->toBeTrue()
        ->and($removals->contains(fn ($c) => $c === "sudo rm -rf /etc/nginx/ssl/domains/{$site->id}/{$extraDomain->id}"))->toBeTrue();

    // The old (wrong) hostname-keyed path must never be used.
    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, "/etc/nginx/ssl/{$site->domain}")
        && ! str_contains($c, '/etc/nginx/ssl/domains/')))->toBeFalse();

    $this->assertDatabaseMissing('sites', ['id' => $site->id]);
});

it('deletes the site even when the server is missing, without attempting SSL cleanup', function () {
    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'source_control_account_id' => null,
    ]);

    // Build the job while the model graph still exists (constructor extracts paths),
    // then delete the server to simulate it being gone when the job actually runs.
    $job = new DeleteSiteJob($site);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connect');
    $this->app->instance(SshService::class, $sshService);

    $server->delete();

    $job->handle(app(SshService::class), app(CleanupSiteExternalResourcesAction::class));

    $this->assertDatabaseMissing('sites', ['id' => $site->id]);
});
