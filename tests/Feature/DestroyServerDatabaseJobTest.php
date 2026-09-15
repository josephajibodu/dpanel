<?php

use App\Actions\Database\DestroyServerDatabase;
use App\Enums\ServerStatus;
use App\Events\ServerDatabasesUpdated;
use App\Jobs\DestroyServerDatabaseJob;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\ServerDatabase;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('removes the deleted database from every database user referencing it', function () {
    Event::fake([ServerDatabasesUpdated::class]);

    $server = Server::factory()->create([
        'database_type' => 'mysql',
        'status' => ServerStatus::Active,
    ]);
    ServerCredential::create([
        'server_id' => $server->id,
        'type' => 'database_password',
        'value' => 'rootsecret',
    ]);

    $serverDatabase = ServerDatabase::factory()->create([
        'server_id' => $server->id,
        'name' => 'testdb',
    ]);
    ServerDatabase::factory()->create([
        'server_id' => $server->id,
        'name' => 'keepdb',
    ]);

    $affectedUser = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'databases' => ['testdb', 'keepdb'],
    ]);
    $unaffectedUser = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'databases' => ['keepdb'],
    ]);

    $mockConnection = Mockery::mock(App\Services\Ssh\SshConnection::class)->makePartial();
    $mockConnection->shouldReceive('exec')->andReturn('');
    $mockConnection->shouldReceive('disconnect')->andReturn(null);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn($mockConnection);

    $this->app->instance(SshService::class, $sshService);

    $job = new DestroyServerDatabaseJob($serverDatabase);
    $job->handle(app(DestroyServerDatabase::class));

    expect(ServerDatabase::find($serverDatabase->id))->toBeNull()
        ->and($affectedUser->fresh()->databases)->toBe(['keepdb'])
        ->and($unaffectedUser->fresh()->databases)->toBe(['keepdb']);

    Event::assertDispatched(ServerDatabasesUpdated::class, fn ($e) => $e->server->id === $server->id);
});
