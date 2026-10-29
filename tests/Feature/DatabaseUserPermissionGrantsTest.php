<?php

use App\Actions\Database\CreateDatabaseUser;
use App\Actions\Database\DestroyDatabaseUser;
use App\Actions\Database\UpdateDatabaseUser;
use App\Enums\ServerStatus;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Record the SQL each action runs. The statements are uploaded as a script
 * rather than passed in the command, so the uploaded scripts are what's kept.
 */
function mockConnectionRecordingCommands(array &$commands): SshConnection
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')
        ->andReturnUsing(fn (string $cmd) => $cmd === 'mktemp -d' ? '/tmp/tmp.fleetops' : '');
    $connection->shouldReceive('upload')
        ->andReturnUsing(function (string $content, string $remotePath) use (&$commands) {
            if (str_ends_with($remotePath, 'statements.sql')) {
                $commands[] = $content;
            }
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

    expect(implode("\n", $commands))
        ->toContain('GRANT ALL PRIVILEGES ON DATABASE "app_db"')
        ->toContain('GRANT ALL ON SCHEMA public TO "'.$user->username.'"');
});

/**
 * A ready Postgres server whose SSH connection records the uploaded SQL scripts.
 */
function postgresServerRecordingCommands(array &$commands): Server
{
    $server = Server::factory()->create(['database_type' => 'postgresql', 'status' => ServerStatus::Active]);
    ServerCredential::create(['server_id' => $server->id, 'type' => 'database_password', 'value' => 'rootsecret']);

    $connection = mockConnectionRecordingCommands($commands);
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $sshService);

    return $server;
}

it('lets a new readonly Postgres user read tables that existing readwrite users create later', function () {
    $commands = [];
    $server = postgresServerRecordingCommands($commands);

    DatabaseUser::factory()->create(['server_id' => $server->id, 'username' => 'app_owner', 'databases' => ['app_db'], 'permission' => 'readwrite', 'status' => 'ready']);
    DatabaseUser::factory()->create(['server_id' => $server->id, 'username' => 'not_yet_created', 'databases' => ['app_db'], 'permission' => 'readwrite', 'status' => 'pending']);
    DatabaseUser::factory()->create(['server_id' => $server->id, 'username' => 'other_db_owner', 'databases' => ['other_db'], 'permission' => 'readwrite', 'status' => 'ready']);

    $reader = DatabaseUser::factory()->create(['server_id' => $server->id, 'username' => 'reader', 'databases' => ['app_db'], 'permission' => 'readonly']);

    app(CreateDatabaseUser::class)->execute($reader);

    expect(implode("\n", $commands))
        ->toContain('ALTER DEFAULT PRIVILEGES FOR ROLE "app_owner" IN SCHEMA public GRANT SELECT ON TABLES TO "reader"')
        ->not->toContain('"not_yet_created"')
        ->not->toContain('"other_db_owner"');
});

it('lets existing readonly Postgres users read tables a new readwrite user creates', function () {
    $commands = [];
    $server = postgresServerRecordingCommands($commands);

    DatabaseUser::factory()->create(['server_id' => $server->id, 'username' => 'reader', 'databases' => ['app_db'], 'permission' => 'readonly', 'status' => 'ready']);

    $owner = DatabaseUser::factory()->create(['server_id' => $server->id, 'username' => 'app_owner', 'databases' => ['app_db'], 'permission' => 'readwrite']);

    app(CreateDatabaseUser::class)->execute($owner);

    expect(implode("\n", $commands))
        ->toContain('ALTER DEFAULT PRIVILEGES FOR ROLE "app_owner" IN SCHEMA public GRANT SELECT ON TABLES TO "reader"');
});

it('revokes schema and future-table privileges before re-granting an updated Postgres user', function () {
    $commands = [];
    $server = postgresServerRecordingCommands($commands);

    DatabaseUser::factory()->create(['server_id' => $server->id, 'username' => 'app_owner', 'databases' => ['app_db'], 'permission' => 'readwrite', 'status' => 'ready']);

    $user = DatabaseUser::factory()->create(['server_id' => $server->id, 'username' => 'switcher', 'databases' => ['app_db'], 'permission' => 'readonly', 'status' => 'ready']);

    app(UpdateDatabaseUser::class)->execute($user);

    $joined = implode("\n", $commands);
    $revokeSchemaAt = strpos($joined, 'REVOKE ALL ON SCHEMA public FROM "switcher"');

    expect($revokeSchemaAt)->not->toBeFalse()
        ->and($joined)->toContain('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT ON TABLES FROM "switcher"')
        ->and($joined)->toContain('ALTER DEFAULT PRIVILEGES FOR ROLE "app_owner" IN SCHEMA public REVOKE SELECT ON TABLES FROM "switcher"')
        ->and(strpos($joined, 'GRANT USAGE ON SCHEMA public TO "switcher"'))->toBeGreaterThan($revokeSchemaAt)
        ->and($joined)->not->toContain('GRANT ALL ON SCHEMA public TO "switcher"');
});

it('hands a deleted Postgres user\'s tables to postgres and clears their grants before dropping them', function () {
    $commands = [];
    $server = postgresServerRecordingCommands($commands);

    $user = DatabaseUser::factory()->create(['server_id' => $server->id, 'username' => 'app_owner', 'databases' => ['app_db', 'logs_db'], 'permission' => 'readwrite', 'status' => 'deleting']);

    app(DestroyDatabaseUser::class)->execute($user);

    expect($commands)->toHaveCount(3)
        ->and($commands[0])->toContain("REASSIGN OWNED BY %I TO postgres', 'app_owner'")
        ->and($commands[0])->toContain("DROP OWNED BY %I', 'app_owner'")
        ->and($commands[2])->toBe('DROP USER IF EXISTS "app_owner";'."\n");
});
