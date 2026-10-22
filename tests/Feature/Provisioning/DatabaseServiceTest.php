<?php

use App\Contracts\Provisioning\PackageManager;
use App\Contracts\Provisioning\ServiceManager;
use App\Contracts\Remote\RemoteCommandRunner;
use App\Contracts\Remote\RemoteFilesystem;
use App\Exceptions\SshCommandException;
use App\Models\Server;
use App\Services\Provisioning\DatabaseService;
use App\Services\Provisioning\ProvisioningContext;

/**
 * @param  array<int, string>  $commands
 * @param  array<string, string>  $uploads
 */
function databaseProvisioningContext(string $databaseType, string $password, array &$commands, array &$uploads, ?Closure $onCommand = null): ProvisioningContext
{
    $runner = Mockery::mock(RemoteCommandRunner::class);
    $runner->shouldReceive('run', 'runQuietly')->andReturnUsing(function (string $command) use (&$commands, $onCommand) {
        $commands[] = $command;

        if ($onCommand) {
            $onCommand($command);
        }

        return $command === 'mktemp -d' ? "/tmp/tmp.fleetops\n" : '';
    });

    $files = Mockery::mock(RemoteFilesystem::class);
    $files->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use (&$uploads) {
        $uploads[$path] = $contents;
    });

    $packages = Mockery::mock(PackageManager::class);
    $packages->shouldReceive('ensureInstalled');

    $services = Mockery::mock(ServiceManager::class);
    $services->shouldReceive('enable', 'start');

    return new ProvisioningContext(
        server: Server::factory()->make(['database_type' => $databaseType]),
        runner: $runner,
        files: $files,
        packages: $packages,
        services: $services,
        serverUser: 'artisan',
        sudoPassword: '',
        databasePassword: $password,
    );
}

it('never puts the database root password in a command', function (string $databaseType) {
    $commands = [];
    $uploads = [];
    $password = 'Sup3r-Secret-Pw';

    app(DatabaseService::class)->installForServer(databaseProvisioningContext($databaseType, $password, $commands, $uploads));

    expect($commands)->not->toBeEmpty();

    foreach ($commands as $command) {
        expect($command)->not->toContain($password);
    }

    expect($commands)->toContain("rm -rf '/tmp/tmp.fleetops'");
})->with(['mysql', 'mariadb', 'postgresql']);

it('feeds the uploaded SQL file to the database client', function (string $databaseType, string $expectedCommand) {
    $commands = [];
    $uploads = [];

    app(DatabaseService::class)->installForServer(databaseProvisioningContext($databaseType, 'pw', $commands, $uploads));

    expect($commands)->toBe([
        'mktemp -d',
        $expectedCommand,
        "rm -rf '/tmp/tmp.fleetops'",
    ]);
})->with([
    'mysql' => ['mysql', "sudo mysql < '/tmp/tmp.fleetops/statements.sql' || true"],
    'mariadb' => ['mariadb', "sudo mysql < '/tmp/tmp.fleetops/statements.sql' || true"],
    'postgresql' => ['postgresql', "sudo -u postgres psql -v ON_ERROR_STOP=1 < '/tmp/tmp.fleetops/statements.sql'"],
]);

it('uploads the ALTER USER statement with the password correctly quoted', function (string $databaseType, string $expectedSql) {
    $commands = [];
    $uploads = [];

    app(DatabaseService::class)->installForServer(databaseProvisioningContext($databaseType, "it's\\a pw", $commands, $uploads));

    expect($uploads)->toBe(['/tmp/tmp.fleetops/statements.sql' => $expectedSql]);
})->with([
    'mysql' => ['mysql', "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'it\\'s\\\\a pw';\nFLUSH PRIVILEGES;\n"],
    'mariadb' => ['mariadb', "ALTER USER 'root'@'localhost' IDENTIFIED BY 'it\\'s\\\\a pw';\nFLUSH PRIVILEGES;\n"],
    'postgresql' => ['postgresql', "ALTER USER postgres PASSWORD 'it''s\\a pw';\n"],
]);

it('removes the private directory even when the database command fails', function () {
    $commands = [];
    $uploads = [];

    $context = databaseProvisioningContext('postgresql', 'pw', $commands, $uploads, function (string $command) {
        if (str_starts_with($command, 'sudo -u postgres psql')) {
            throw new SshCommandException(command: $command, exitCode: 1, output: '');
        }
    });

    expect(fn () => app(DatabaseService::class)->installForServer($context))
        ->toThrow(SshCommandException::class);

    expect(end($commands))->toBe("rm -rf '/tmp/tmp.fleetops'");
});

it('refuses to continue when a private directory could not be created', function () {
    $commands = [];
    $uploads = [];

    $context = databaseProvisioningContext('mysql', 'pw', $commands, $uploads);
    $context->runner = Mockery::mock(RemoteCommandRunner::class);
    $context->runner->shouldReceive('run')->with('mktemp -d', 10)->andReturn('mktemp: failed');
    $context->runner->shouldNotReceive('runQuietly');

    expect(fn () => app(DatabaseService::class)->installForServer($context))
        ->toThrow(RuntimeException::class, 'Could not create a private temporary directory');

    expect($uploads)->toBeEmpty();
});
