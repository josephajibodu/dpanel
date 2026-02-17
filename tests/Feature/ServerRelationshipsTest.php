<?php

use App\Contracts\Provisioning\ServiceManager;
use App\Models\CronJob;
use App\Models\DatabaseUser;
use App\Models\FirewallRule;
use App\Models\Metric;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerLog;
use App\Models\Service;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('associates server with databases', function () {
    $server = Server::factory()->create();
    ServerDatabase::factory()->count(2)->create(['server_id' => $server->id]);

    $server->refresh();

    expect($server->databases)->toHaveCount(2)
        ->and($server->databases->first())->toBeInstanceOf(ServerDatabase::class);
});

it('associates server with database users', function () {
    $server = Server::factory()->create();
    DatabaseUser::factory()->count(1)->create(['server_id' => $server->id]);

    $server->refresh();

    expect($server->databaseUsers)->toHaveCount(1);
});

it('associates server with firewall rules', function () {
    $server = Server::factory()->create();
    FirewallRule::factory()->count(1)->create(['server_id' => $server->id]);

    $server->refresh();

    expect($server->firewallRules)->toHaveCount(1);
});

it('associates server with cron jobs and workers', function () {
    $server = Server::factory()->create();
    CronJob::factory()->count(1)->create(['server_id' => $server->id]);
    Worker::factory()->count(2)->create(['server_id' => $server->id, 'site_id' => null]);

    $server->refresh();

    expect($server->cronJobs)->toHaveCount(1)
        ->and($server->workers)->toHaveCount(2)
        ->and($server->daemons)->toHaveCount(2);
});

it('associates server with metrics and logs', function () {
    $server = Server::factory()->create();
    Metric::factory()->count(1)->create(['server_id' => $server->id]);
    ServerLog::factory()->count(1)->create(['server_id' => $server->id]);

    $server->refresh();

    expect($server->metrics)->toHaveCount(1)
        ->and($server->logs)->toHaveCount(1);
});

it('associates server with installed services', function () {
    $server = Server::factory()->create();
    Service::factory()->nginx()->create(['server_id' => $server->id]);
    Service::factory()->php()->create(['server_id' => $server->id]);

    $server->refresh();

    expect($server->installedServices)->toHaveCount(2)
        ->and($server->installedServices->first())->toBeInstanceOf(Service::class);
});

it('service lifecycle methods delegate to service manager', function () {
    $service = Service::factory()->nginx()->create(['unit' => 'nginx']);
    $manager = \Mockery::mock(ServiceManager::class);

    $manager->shouldReceive('start')->once()->with('nginx');
    $service->start($manager);

    $manager->shouldReceive('stop')->once()->with('nginx');
    $service->stop($manager);

    $manager->shouldReceive('restart')->once()->with('nginx');
    $service->restart($manager);

    $manager->shouldReceive('reload')->once()->with('nginx');
    $service->reload($manager);

    $manager->shouldReceive('enable')->once()->with('nginx');
    $service->enable($manager);

    $manager->shouldReceive('disable')->once()->with('nginx');
    $service->disable($manager);
});
