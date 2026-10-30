<?php

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\Server;
use App\Services\Provisioning\CaddyService;
use App\Services\Provisioning\DatabaseService;
use App\Services\Provisioning\FinalTouchesService;
use App\Services\Provisioning\NginxService;
use App\Services\Provisioning\PhpService;
use App\Services\Provisioning\RedisService;
use App\Services\Provisioning\StackInstaller;
use App\Services\Provisioning\SystemService;
use App\Services\Ssh\SshConnection;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();

    $this->mock(SystemService::class)->shouldIgnoreMissing();
    $this->mock(FinalTouchesService::class)->shouldIgnoreMissing();

    $this->php = $this->mock(PhpService::class);
    $this->nginx = $this->mock(NginxService::class);
    $this->caddy = $this->mock(CaddyService::class);
    $this->database = $this->mock(DatabaseService::class);
    $this->redis = $this->mock(RedisService::class);

    $this->connection = Mockery::mock(SshConnection::class);
    $this->connection->shouldIgnoreMissing('');
});

it('installs only the services chosen for the server', function () {
    $server = Server::factory()->cache()->pending()->create();
    $server->queueSelectedServices(withRedis: true);

    $this->redis->shouldReceive('install')->once();
    $this->php->shouldNotReceive('install');
    $this->nginx->shouldNotReceive('install');
    $this->database->shouldNotReceive('installForServer');

    app(StackInstaller::class)->install($server, $this->connection);

    expect($server->installedServices()->pluck('status', 'type')->all())->toBe([
        ServiceType::Redis->value => ServiceStatus::Active,
        ServiceType::Supervisor->value => ServiceStatus::Active,
    ]);
});

it('skips services that a previous attempt already installed', function () {
    $server = Server::factory()->pending()->create(['database_type' => null]);
    $server->queueSelectedServices(withRedis: false);
    $server->php()->update(['status' => ServiceStatus::Active]);
    $server->nginx()->update(['status' => ServiceStatus::Failed]);

    $this->php->shouldNotReceive('install');
    $this->nginx->shouldReceive('install')->once();

    app(StackInstaller::class)->install($server, $this->connection);

    expect($server->nginx()->status)->toBe(ServiceStatus::Active);
});

it('falls back to the type preset for servers created before services were recorded', function () {
    $server = Server::factory()->worker()->pending()->create();

    $this->php->shouldReceive('install', 'configureFpmPool')->once();
    $this->redis->shouldReceive('install')->once();
    $this->nginx->shouldNotReceive('install');
    $this->database->shouldNotReceive('installForServer');

    app(StackInstaller::class)->install($server, $this->connection);

    expect($server->installedServices()->pluck('type')->all())
        ->toEqualCanonicalizing([ServiceType::Php, ServiceType::Redis, ServiceType::Supervisor]);
});

it('installs Caddy instead of Nginx when Caddy was chosen', function () {
    $server = Server::factory()->pending()->create(['web_server' => 'caddy', 'database_type' => null]);
    $server->queueSelectedServices(withRedis: false);

    $this->php->shouldReceive('install', 'configureFpmPool')->once();
    $this->caddy->shouldReceive('install')->once();
    $this->nginx->shouldNotReceive('install');

    app(StackInstaller::class)->install($server, $this->connection);

    expect($server->installedServices()->where('type', ServiceType::Caddy)->sole())
        ->status->toBe(ServiceStatus::Active)
        ->unit->toBe('caddy');
});
