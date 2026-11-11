<?php

use App\Enums\ServerStatus;
use App\Enums\SiteStatus;
use App\Models\Server;
use App\Models\Site;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fakes SSH so every sync succeeds except on the given server, and returns
 * the ids of the servers that were connected to.
 *
 * @return ArrayObject<int, int>
 */
function fakeSshForSiteSync(?Server $unreachable = null): ArrayObject
{
    $connected = new ArrayObject;

    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('exec')->andReturn('');
    $connection->shouldReceive('disconnect');

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturnUsing(function (Server $server) use ($connected, $connection, $unreachable) {
        $connected->append($server->id);

        if ($unreachable?->is($server)) {
            throw new RuntimeException('Connection refused');
        }

        return $connection;
    });
    app()->instance(SshService::class, $sshService);

    return $connected;
}

it('requires --server or --all', function () {
    $this->artisan('flitops:web-server:sync-sites')->assertFailed();
});

it('rebuilds the configs of sites on the given servers only', function () {
    $target = Server::factory()->create(['status' => ServerStatus::Active]);
    $other = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->forServer($target)->create(['status' => SiteStatus::Deployed]);
    Site::factory()->forServer($other)->create(['status' => SiteStatus::Deployed]);
    $connected = fakeSshForSiteSync();

    $this->artisan('flitops:web-server:sync-sites', ['--server' => [$target->id]])->assertSuccessful();

    expect($connected->getArrayCopy())->toBe([$target->id]);
    $this->assertDatabaseHas('site_nginx_files', ['site_id' => $site->id, 'section' => 'main', 'sync_status' => 'synced']);
});

it('covers Nginx and Caddy servers with --all, skips sites still being set up, and reports a failure without stopping', function () {
    $nginx = Server::factory()->create(['status' => ServerStatus::Active, 'web_server' => 'nginx']);
    $caddy = Server::factory()->create(['status' => ServerStatus::Active, 'web_server' => 'caddy']);
    $unreachable = Server::factory()->create(['status' => ServerStatus::Active]);
    Site::factory()->forServer($unreachable)->create(['status' => SiteStatus::Deployed]);
    Site::factory()->forServer($nginx)->create(['status' => SiteStatus::Deployed]);
    Site::factory()->forServer($nginx)->create(['status' => SiteStatus::Installing]);
    Site::factory()->forServer($caddy)->create(['status' => SiteStatus::Deployed]);
    $connected = fakeSshForSiteSync($unreachable);

    $this->artisan('flitops:web-server:sync-sites', ['--all' => true])->assertFailed();

    expect($connected->getArrayCopy())->toEqualCanonicalizing([$nginx->id, $caddy->id, $unreachable->id]);
});
