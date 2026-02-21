<?php

use App\Actions\Database\CreateServerDatabase;
use App\Jobs\CreateServerDatabaseJob;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\ServerDatabase;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates database on server and sets status to ready', function () {
    $server = Server::factory()->create([
        'database_type' => 'mysql',
        'status' => \App\Enums\ServerStatus::Active,
    ]);
    ServerCredential::create([
        'server_id' => $server->id,
        'type' => 'database_password',
        'value' => 'rootsecret',
    ]);

    $serverDatabase = ServerDatabase::factory()->create([
        'server_id' => $server->id,
        'name' => 'testdb',
        'status' => 'pending',
    ]);

    $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
    $mockConnection->shouldReceive('exec')->andReturn('');
    $mockConnection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($mockConnection);

    $this->app->instance(SshService::class, $sshService);

    $job = new CreateServerDatabaseJob($serverDatabase);
    $job->handle(app(CreateServerDatabase::class));

    $serverDatabase->refresh();
    expect($serverDatabase->status)->toBe('ready');
});

it('sets status to failed when server has no database password credential', function () {
    $server = Server::factory()->create([
        'database_type' => 'mysql',
        'status' => \App\Enums\ServerStatus::Active,
    ]);
    // No database_password credential

    $serverDatabase = ServerDatabase::factory()->create([
        'server_id' => $server->id,
        'name' => 'testdb',
        'status' => 'pending',
    ]);

    $job = new CreateServerDatabaseJob($serverDatabase);
    try {
        $job->handle(app(CreateServerDatabase::class));
    } catch (\RuntimeException) {
        // Job sets status to failed then rethrows
    }

    $serverDatabase->refresh();
    expect($serverDatabase->status)->toBe('failed');
});
