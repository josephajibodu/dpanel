<?php

use App\Actions\Database\CreateDatabaseUser;
use App\Actions\Database\UpdateDatabaseUser;
use App\Enums\ServerStatus;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mockConnectionRecordingCommands(array &$commands): SshConnection
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')
        ->andReturnUsing(function (string $cmd) use (&$commands) {
            $commands[] = $cmd;

            return '';
        });
    $connection->shouldReceive('disconnect')->andReturn(null);

    return $connection;
}

// --- MySQL ---

it('grants only SELECT for a readonly MySQL user on create', function () {
    $server = Server::factory()->create(['database_type' => 'mysql', 'status' => ServerStatus::Active]);
    ServerCredential::create(['server_id' => $server->id, 'type' => 'database_password', 'value' => 'rootsecret']);

    $user = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'databases' => ['app_db'],
        'permission' => 'readonly',
    ]);

    $commands = [];
    $connection = mockConnectionRecordingCommands($commands);
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    app(CreateDatabaseUser::class)->execute($user);

    expect(implode("\n", $commands))
        ->toContain('GRANT SELECT ON `app_db`.*')
        ->not->toContain('GRANT ALL PRIVILEGES');
});

it('grants ALL PRIVILEGES for a readwrite MySQL user on create', function () {
    $server = Server::factory()->create(['database_type' => 'mysql', 'status' => ServerStatus::Active]);
    ServerCredential::create(['server_id' => $server->id, 'type' => 'database_password', 'value' => 'rootsecret']);

    $user = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'databases' => ['app_db'],
        'permission' => 'readwrite',
    ]);

    $commands = [];
    $connection = mockConnectionRecordingCommands($commands);
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    app(CreateDatabaseUser::class)->execute($user);

    expect(implode("\n", $commands))->toContain('GRANT ALL PRIVILEGES ON `app_db`.*');
});

it('re-grants only SELECT for a MySQL user updated to readonly', function () {
    $server = Server::factory()->create(['database_type' => 'mysql', 'status' => ServerStatus::Active]);
    ServerCredential::create(['server_id' => $server->id, 'type' => 'database_password', 'value' => 'rootsecret']);

    $user = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'databases' => ['app_db'],
        'permission' => 'readonly',
    ]);

    $commands = [];
    $connection = mockConnectionRecordingCommands($commands);
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    app(UpdateDatabaseUser::class)->execute($user);

    $joined = implode("\n", $commands);
    expect($joined)->toContain('REVOKE ALL PRIVILEGES')
        ->and($joined)->toContain('GRANT SELECT ON `app_db`.*');
});

// --- PostgreSQL ---

it('grants schema-level SELECT only for a readonly Postgres user on create', function () {
    $server = Server::factory()->create(['database_type' => 'postgresql', 'status' => ServerStatus::Active]);
    ServerCredential::create(['server_id' => $server->id, 'type' => 'database_password', 'value' => 'rootsecret']);

    $user = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'databases' => ['app_db'],
        'permission' => 'readonly',
    ]);

    $commands = [];
    $connection = mockConnectionRecordingCommands($commands);
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    app(CreateDatabaseUser::class)->execute($user);

    $joined = implode("\n", $commands);
    expect($joined)->toContain('GRANT CONNECT ON DATABASE "app_db"')
        ->and($joined)->toContain('GRANT SELECT ON ALL TABLES IN SCHEMA public')
        ->and($joined)->toContain('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES')
        ->and($joined)->not->toContain('GRANT ALL PRIVILEGES ON DATABASE');
});

it('grants ALL PRIVILEGES on the database for a readwrite Postgres user on create', function () {
    $server = Server::factory()->create(['database_type' => 'postgresql', 'status' => ServerStatus::Active]);
    ServerCredential::create(['server_id' => $server->id, 'type' => 'database_password', 'value' => 'rootsecret']);

    $user = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'databases' => ['app_db'],
        'permission' => 'readwrite',
    ]);

    $commands = [];
    $connection = mockConnectionRecordingCommands($commands);
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    app(CreateDatabaseUser::class)->execute($user);

    expect(implode("\n", $commands))->toContain('GRANT ALL PRIVILEGES ON DATABASE "app_db"');
});
