<?php

use App\Actions\Sites\CleanupSiteExternalResourcesAction;
use App\Enums\SiteDomainType;
use App\Jobs\DeleteSiteJob;
use App\Jobs\DestroyCronJobJob;
use App\Jobs\DestroyWorkerJob;
use App\Models\CronJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Worker;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

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

it('removes the nginx snippet directory for every domain on the site', function () {
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

    $removals = collect($execCalls)->filter(fn ($c) => str_contains($c, '/etc/nginx/flitops-conf/'));

    expect($removals)->toHaveCount(2)
        ->and($removals->contains(fn ($c) => $c === 'sudo rm -rf '.escapeshellarg("/etc/nginx/flitops-conf/{$site->ulid}/{$primaryDomain->hostname}")))->toBeTrue()
        ->and($removals->contains(fn ($c) => $c === 'sudo rm -rf '.escapeshellarg("/etc/nginx/flitops-conf/{$site->ulid}/{$extraDomain->hostname}")))->toBeTrue();
});

it('destroys workers and cron jobs tied to the site, but leaves other sites untouched', function () {
    Queue::fake([DestroyWorkerJob::class, DestroyCronJobJob::class]);

    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'source_control_account_id' => null,
    ]);
    $otherSite = Site::factory()->create([
        'server_id' => $server->id,
        'source_control_account_id' => null,
    ]);

    $worker = Worker::factory()->forSite($site)->create();
    $cronJob = CronJob::factory()->forSite($site)->create();
    $otherWorker = Worker::factory()->forSite($otherSite)->create();
    $otherCronJob = CronJob::factory()->forSite($otherSite)->create();

    $execCalls = [];
    $this->app->instance(SshService::class, fakeSshForDeleteSiteJob($server, $execCalls));

    $job = new DeleteSiteJob($site);
    $job->handle(app(SshService::class), app(CleanupSiteExternalResourcesAction::class));

    Queue::assertPushed(DestroyWorkerJob::class, fn (DestroyWorkerJob $job) => $job->worker->is($worker));
    Queue::assertPushed(DestroyCronJobJob::class, fn (DestroyCronJobJob $job) => $job->cronJob->is($cronJob));
    Queue::assertNotPushed(DestroyWorkerJob::class, fn (DestroyWorkerJob $job) => $job->worker->is($otherWorker));
    Queue::assertNotPushed(DestroyCronJobJob::class, fn (DestroyCronJobJob $job) => $job->cronJob->is($otherCronJob));
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
