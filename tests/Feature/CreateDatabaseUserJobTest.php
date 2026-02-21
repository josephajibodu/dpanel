<?php

use App\Actions\Database\CreateDatabaseUser;
use App\Jobs\CreateDatabaseUserJob;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\ServerDatabase;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates database user on server and sets status to ready', function () {
    $server = Server::factory()->create([
        'database_type' => 'mysql',
        'status' => \App\Enums\ServerStatus::Active,
    ]);
    ServerCredential::create([
        'server_id' => $server->id,
        'type' => 'database_password',
        'value' => 'rootsecret',
    ]);

    ServerDatabase::factory()->create([
        'server_id' => $server->id,
        'name' => 'app_db',
    ]);

    $databaseUser = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'username' => 'myuser',
        'password' => 'userpass123',
        'databases' => ['app_db'],
        'host' => 'localhost',
        'status' => 'pending',
    ]);

    $mockConnection = \Mockery::mock(\App\Services\Ssh\SshConnection::class)->makePartial();
    $mockConnection->shouldReceive('exec')->andReturn('');
    $mockConnection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($mockConnection);

    $this->app->instance(SshService::class, $sshService);

    $job = new CreateDatabaseUserJob($databaseUser);
    $job->handle(app(CreateDatabaseUser::class));

    $databaseUser->refresh();
    expect($databaseUser->status)->toBe('ready');
});

it('sets status to failed when server has no database password credential', function () {
    $server = Server::factory()->create([
        'database_type' => 'mysql',
        'status' => \App\Enums\ServerStatus::Active,
    ]);
    ServerDatabase::factory()->create([
        'server_id' => $server->id,
        'name' => 'app_db',
    ]);
    // No database_password credential

    $databaseUser = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'username' => 'myuser',
        'password' => 'userpass123',
        'databases' => ['app_db'],
        'status' => 'pending',
    ]);

    $job = new CreateDatabaseUserJob($databaseUser);
    try {
        $job->handle(app(CreateDatabaseUser::class));
    } catch (\RuntimeException) {
        // Job sets status to failed then rethrows
    }

    $databaseUser->refresh();
    expect($databaseUser->status)->toBe('failed');
});
