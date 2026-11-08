<?php

use App\Actions\Servers\RevokeFlitopsAccessAction;
use App\Actions\Sites\CleanupSiteExternalResourcesAction;
use App\Contracts\ProviderContract;
use App\Events\ServerDeleted;
use App\Jobs\DeleteServerJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Providers\ProviderManager;
use App\Services\SourceControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('broadcasts ServerDeleted when job completes successfully', function () {
    Event::fake([ServerDeleted::class]);

    $server = Server::factory()->create([
        'provider_server_id' => null,
        'meta' => [],
    ]);

    $mockProvider = Mockery::mock(ProviderContract::class)->makePartial();
    $mockProvider->shouldReceive('deleteServer')->andReturn(null);
    $mockProvider->shouldReceive('deleteSshKey')->andReturn(null);

    $mockProviderManager = Mockery::mock(ProviderManager::class);
    $mockProviderManager->shouldReceive('forAccount')
        ->with(Mockery::on(fn ($account) => $account->id === $server->providerAccount->id))
        ->andReturn($mockProvider);

    $mockSourceControl = Mockery::mock(SourceControlService::class);
    $mockSourceControl->shouldReceive('deleteAllAccountSshKeysForServer')
        ->with(Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn(null);

    $this->app->instance(ProviderManager::class, $mockProviderManager);
    $this->app->instance(SourceControlService::class, $mockSourceControl);

    $job = new DeleteServerJob($server);
    $job->handle(
        app(ProviderManager::class),
        app(SourceControlService::class),
        app(CleanupSiteExternalResourcesAction::class),
        app(RevokeFlitopsAccessAction::class),
    );

    Event::assertDispatched(ServerDeleted::class, function (ServerDeleted $event) use ($server) {
        return $event->serverId === $server->id
            && $event->teamId === $server->team_id;
    });

    $this->assertDatabaseMissing('servers', ['id' => $server->id]);
});

it('cleans up cloudflare DNS records for each site before destroying the VPS', function () {
    $server = Server::factory()->create([
        'provider_server_id' => null,
        'meta' => [],
    ]);

    $siteA = Site::factory()->forServer($server)->create();
    $siteB = Site::factory()->forServer($server)->create();

    // Attach Cloudflare record IDs to each site's primary domain.
    SiteDomain::where('site_id', $siteA->id)->update(['cloudflare_dns_record_id' => 'cf-rec-A']);
    SiteDomain::where('site_id', $siteB->id)->update(['cloudflare_dns_record_id' => 'cf-rec-B']);

    $mockProvider = Mockery::mock(ProviderContract::class)->makePartial();
    $mockProvider->shouldReceive('deleteServer')->andReturn(null);
    $mockProvider->shouldReceive('deleteSshKey')->andReturn(null);

    $mockProviderManager = Mockery::mock(ProviderManager::class);
    $mockProviderManager->shouldReceive('forAccount')->andReturn($mockProvider);

    $mockSourceControl = Mockery::mock(SourceControlService::class);
    $mockSourceControl->shouldReceive('deleteAllAccountSshKeysForServer')->andReturn(null);

    // Spy on the cleanup action — it should be called once per site, both with
    // cleanupGithubSshKey: false (the server-wide GitHub sweep handles that).
    $mockCleanup = Mockery::mock(CleanupSiteExternalResourcesAction::class);
    $mockCleanup->shouldReceive('execute')
        ->once()
        ->withArgs(function (array $recordIds, $serverArg, $accountArg, $domain, $cleanupGithubSshKey) use ($siteA) {
            return $recordIds === ['cf-rec-A']
                && $domain === $siteA->domain
                && $cleanupGithubSshKey === false;
        });
    $mockCleanup->shouldReceive('execute')
        ->once()
        ->withArgs(function (array $recordIds, $serverArg, $accountArg, $domain, $cleanupGithubSshKey) use ($siteB) {
            return $recordIds === ['cf-rec-B']
                && $domain === $siteB->domain
                && $cleanupGithubSshKey === false;
        });

    $this->app->instance(ProviderManager::class, $mockProviderManager);
    $this->app->instance(SourceControlService::class, $mockSourceControl);
    $this->app->instance(CleanupSiteExternalResourcesAction::class, $mockCleanup);

    $job = new DeleteServerJob($server);
    $job->handle(
        app(ProviderManager::class),
        app(SourceControlService::class),
        app(CleanupSiteExternalResourcesAction::class),
        app(RevokeFlitopsAccessAction::class),
    );

    $this->assertDatabaseMissing('servers', ['id' => $server->id]);
});

it('continues server deletion even if a site cleanup throws', function () {
    $server = Server::factory()->create([
        'provider_server_id' => null,
        'meta' => [],
    ]);

    Site::factory()->forServer($server)->create();

    $mockProvider = Mockery::mock(ProviderContract::class)->makePartial();
    $mockProvider->shouldReceive('deleteServer')->andReturn(null);
    $mockProvider->shouldReceive('deleteSshKey')->andReturn(null);

    $mockProviderManager = Mockery::mock(ProviderManager::class);
    $mockProviderManager->shouldReceive('forAccount')->andReturn($mockProvider);

    $mockSourceControl = Mockery::mock(SourceControlService::class);
    $mockSourceControl->shouldReceive('deleteAllAccountSshKeysForServer')->once()->andReturn(null);

    $mockCleanup = Mockery::mock(CleanupSiteExternalResourcesAction::class);
    $mockCleanup->shouldReceive('execute')->andThrow(new RuntimeException('cloudflare down'));

    $this->app->instance(ProviderManager::class, $mockProviderManager);
    $this->app->instance(SourceControlService::class, $mockSourceControl);
    $this->app->instance(CleanupSiteExternalResourcesAction::class, $mockCleanup);

    $job = new DeleteServerJob($server);
    $job->handle(
        app(ProviderManager::class),
        app(SourceControlService::class),
        app(CleanupSiteExternalResourcesAction::class),
        app(RevokeFlitopsAccessAction::class),
    );

    $this->assertDatabaseMissing('servers', ['id' => $server->id]);
});

it('skips provider API calls when deleting a custom server', function () {
    Event::fake([ServerDeleted::class]);

    $server = Server::factory()->custom()->create();

    // ProviderManager must NOT be called for custom servers
    $mockProviderManager = Mockery::mock(ProviderManager::class);
    $mockProviderManager->shouldNotReceive('forAccount');

    $mockSourceControl = Mockery::mock(SourceControlService::class);
    $mockSourceControl->shouldReceive('deleteAllAccountSshKeysForServer')->once()->andReturn(null);

    $this->app->instance(ProviderManager::class, $mockProviderManager);
    $this->app->instance(SourceControlService::class, $mockSourceControl);

    $job = new DeleteServerJob($server);
    $job->handle(
        app(ProviderManager::class),
        app(SourceControlService::class),
        app(CleanupSiteExternalResourcesAction::class),
        app(RevokeFlitopsAccessAction::class),
    );

    Event::assertDispatched(ServerDeleted::class, fn ($e) => $e->serverId === $server->id);
    $this->assertDatabaseMissing('servers', ['id' => $server->id]);
});

/**
 * Run the job for a custom server with every outside service stubbed, so a
 * test only has to say what it expects of our SSH key removal.
 */
function runDeleteJobForCustomServer(Server $server, RevokeFlitopsAccessAction $revokeFlitopsAccess): void
{
    $mockSourceControl = Mockery::mock(SourceControlService::class);
    $mockSourceControl->shouldReceive('deleteAllAccountSshKeysForServer')->andReturn(null);

    (new DeleteServerJob($server))->handle(
        Mockery::mock(ProviderManager::class),
        $mockSourceControl,
        app(CleanupSiteExternalResourcesAction::class),
        $revokeFlitopsAccess,
    );
}

it('removes our SSH key from a custom server before forgetting it', function () {
    $server = Server::factory()->custom()->create();

    $revoke = Mockery::mock(RevokeFlitopsAccessAction::class);
    $revoke->shouldReceive('execute')->once()->with(Mockery::on(fn ($s) => $s->id === $server->id));

    runDeleteJobForCustomServer($server, $revoke);

    $this->assertDatabaseMissing('servers', ['id' => $server->id]);
});

it('still disconnects a custom server when our SSH key cannot be removed', function () {
    $server = Server::factory()->custom()->create();

    $revoke = Mockery::mock(RevokeFlitopsAccessAction::class);
    $revoke->shouldReceive('execute')->once()->andThrow(new RuntimeException('Connection timed out'));

    runDeleteJobForCustomServer($server, $revoke);

    $this->assertDatabaseMissing('servers', ['id' => $server->id]);
});

it('does not try to remove our SSH key from a provider server it is destroying', function () {
    $server = Server::factory()->create(['provider_server_id' => null, 'meta' => []]);

    $mockProviderManager = Mockery::mock(ProviderManager::class);
    $mockProviderManager->shouldReceive('forAccount')->andReturn(Mockery::mock(ProviderContract::class)->makePartial());

    $mockSourceControl = Mockery::mock(SourceControlService::class);
    $mockSourceControl->shouldReceive('deleteAllAccountSshKeysForServer')->andReturn(null);

    $revoke = Mockery::mock(RevokeFlitopsAccessAction::class);
    $revoke->shouldNotReceive('execute');

    (new DeleteServerJob($server))->handle(
        $mockProviderManager,
        $mockSourceControl,
        app(CleanupSiteExternalResourcesAction::class),
        $revoke,
    );

    $this->assertDatabaseMissing('servers', ['id' => $server->id]);
});
