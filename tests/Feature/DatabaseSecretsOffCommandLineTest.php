<?php

use App\Actions\Database\CreateDatabaseUser;
use App\Actions\Database\CreateServerDatabase;
use App\Actions\Database\DestroyDatabaseUser;
use App\Actions\Database\UpdateDatabaseUser;
use App\Enums\ServerStatus;
use App\Exceptions\SshCommandException;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\ServerDatabase;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const ROOT_PASSWORD = 'RootSecret0123456789abcdefABCDEF';
const USER_PASSWORD = 'UserSecret-do-not-leak';

function databaseServer(string $type): Server
{
    $server = Server::factory()->create(['database_type' => $type, 'status' => ServerStatus::Active]);
    ServerCredential::create(['server_id' => $server->id, 'type' => 'database_password', 'value' => ROOT_PASSWORD]);

    return $server;
}

function useConnection(SshConnection $connection): void
{
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $sshService);
}

it('keeps every password out of the commands it runs', function (string $databaseType, Closure $runAction) {
    $server = databaseServer($databaseType);
    ServerDatabase::factory()->create(['server_id' => $server->id, 'name' => 'app_db']);
    $databaseUser = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'username' => 'app',
        'password' => USER_PASSWORD,
        'databases' => ['app_db'],
    ]);

    $uploads = [];
    $commands = [];
    useConnection(mockDatabaseConnection($uploads, $commands));

    $runAction($server, $databaseUser);

    expect($commands)->not->toBeEmpty();

    foreach ($commands as $command) {
        expect($command)
            ->not->toContain(ROOT_PASSWORD)
            ->not->toContain(USER_PASSWORD)
            ->not->toContain('PGPASSWORD');
    }
})->with([
    'mysql create user' => ['mysql', fn ($server, $user) => app(CreateDatabaseUser::class)->execute($user)],
    'mysql update user' => ['mysql', fn ($server, $user) => app(UpdateDatabaseUser::class)->execute($user)],
    'mysql drop user' => ['mysql', fn ($server, $user) => app(DestroyDatabaseUser::class)->execute($user)],
    'mariadb create user' => ['mariadb', fn ($server, $user) => app(CreateDatabaseUser::class)->execute($user)],
    'postgres create user' => ['postgresql', fn ($server, $user) => app(CreateDatabaseUser::class)->execute($user)],
    'postgres update user' => ['postgresql', fn ($server, $user) => app(UpdateDatabaseUser::class)->execute($user)],
    'mysql create database' => ['mysql', fn ($server) => app(CreateServerDatabase::class)->execute(
        ServerDatabase::factory()->create(['server_id' => $server->id, 'name' => 'other_db'])
    )],
]);

it('points mysql at a private credentials file and sql script, then removes them', function () {
    $server = databaseServer('mysql');
    $databaseUser = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'username' => 'app',
        'password' => USER_PASSWORD,
        'databases' => ['app_db'],
    ]);

    $uploads = [];
    $commands = [];
    useConnection(mockDatabaseConnection($uploads, $commands));

    app(CreateDatabaseUser::class)->execute($databaseUser);

    expect($uploads['/tmp/tmp.fleetops/client.cnf'])->toBe("[client]\nuser=root\npassword=\"".ROOT_PASSWORD."\"\n")
        ->and($uploads['/tmp/tmp.fleetops/statements.sql'])->toContain(USER_PASSWORD);

    expect($commands)->toBe([
        'mktemp -d',
        "sudo mysql --defaults-extra-file='/tmp/tmp.fleetops/client.cnf' < '/tmp/tmp.fleetops/statements.sql'",
        "rm -rf '/tmp/tmp.fleetops'",
    ]);
});

it('runs postgres sql from a script as the local postgres user and stops at the first error', function () {
    $server = databaseServer('postgresql');
    $databaseUser = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'username' => 'app',
        'password' => USER_PASSWORD,
        'databases' => ['app_db'],
        'permission' => 'readwrite',
    ]);

    $uploads = [];
    $commands = [];
    useConnection(mockDatabaseConnection($uploads, $commands));

    app(CreateDatabaseUser::class)->execute($databaseUser);

    $psqlCommands = array_values(array_filter($commands, fn (string $command) => str_contains($command, 'psql')));

    expect($psqlCommands)->toHaveCount(2)
        ->each->toBe("sudo -u postgres psql -v ON_ERROR_STOP=1 < '/tmp/tmp.fleetops/statements.sql'");
});

it('removes the private directory even when the sql fails', function () {
    $server = databaseServer('mysql');
    $databaseUser = DatabaseUser::factory()->create(['server_id' => $server->id, 'databases' => ['app_db']]);

    $commands = [];
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('upload');
    $connection->shouldReceive('disconnect');
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        if (str_starts_with($command, 'sudo mysql')) {
            throw new SshCommandException(command: $command, exitCode: 1, output: '', stderr: 'ERROR 1396');
        }

        return $command === 'mktemp -d' ? '/tmp/tmp.fleetops' : '';
    });
    useConnection($connection);

    expect(fn () => app(CreateDatabaseUser::class)->execute($databaseUser))->toThrow(SshCommandException::class);

    expect(end($commands))->toBe("rm -rf '/tmp/tmp.fleetops'");
});

it('refuses to continue when the server does not return a temporary directory', function () {
    $server = databaseServer('mysql');
    $databaseUser = DatabaseUser::factory()->create(['server_id' => $server->id, 'databases' => ['app_db']]);

    $commands = [];
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('disconnect');
    $connection->shouldNotReceive('upload');
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        return '';
    });
    useConnection($connection);

    expect(fn () => app(CreateDatabaseUser::class)->execute($databaseUser))
        ->toThrow(RuntimeException::class, 'Could not create a private temporary directory');

    expect($commands)->toBe(['mktemp -d']);
});
