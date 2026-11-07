<?php

use App\Models\Server;
use App\Models\ServerConnection;
use App\Models\ServerCredential;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A mocked SSH connection for the database actions: `mktemp -d` hands back a
 * private directory, uploads are captured by remote path, and every command
 * is recorded, so tests can assert on the SQL that would reach the server.
 * `$onExec` sees each command first and may throw to simulate it failing.
 *
 * @param  array<string, string>  $uploads
 * @param  array<int, string>  $commands
 * @param  (Closure(string): void)|null  $onExec
 */
function mockDatabaseConnection(array &$uploads = [], array &$commands = [], ?Closure $onExec = null): SshConnection
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $onExec) {
        $commands[] = $command;

        if ($onExec) {
            $onExec($command);
        }

        return $command === 'mktemp -d' ? '/tmp/tmp.fleetops' : '';
    });
    $connection->shouldReceive('upload')->andReturnUsing(function (string $content, string $remotePath) use (&$uploads) {
        $uploads[$remotePath] = $content;
    });
    $connection->shouldReceive('disconnect')->andReturn(null);

    return $connection;
}

/**
 * Fake the SSH connection to the host, recording commands and uploaded files.
 *
 * @param  array<int, string>  $commands
 * @param  array<string, string>  $uploads
 * @param  array<string, string>  $outputs  Output to return for commands containing the key.
 */
function fakeHostSsh(array &$commands, array &$uploads, array $outputs = []): void
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $outputs) {
        $commands[] = $command;

        if ($command === 'mktemp -d') {
            return '/tmp/tmp.fleetops';
        }

        foreach ($outputs as $needle => $output) {
            if (str_contains($command, $needle)) {
                return $output;
            }
        }

        return '';
    });
    $connection->shouldReceive('upload')->andReturnUsing(function (string $content, string $path) use (&$uploads) {
        $uploads[$path] = ($uploads[$path] ?? '').$content;
    });
    $connection->shouldReceive('disconnect');

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $sshService);
}

/**
 * A pending connection from a new web server to the host, which gets a database root password.
 */
function connectionTo(Server $host): ServerConnection
{
    ServerCredential::create(['server_id' => $host->id, 'type' => 'database_password', 'value' => 'root-secret']);

    return ServerConnection::factory()->pending()->create([
        'server_id' => $host->id,
        'client_server_id' => Server::factory()->web()->create()->id,
        'client_address' => '10.10.0.3',
    ]);
}

/**
 * The live props a page declares, read from the raw page object because the
 * Inertia test helpers leave them out. Each listener becomes one
 * "type:channel event" string, such as "private:server.5 server.php.updated".
 *
 * @return array<string, array<int, string>>
 */
function liveProps(TestResponse $response): array
{
    return collect($response->viewData('page')['liveProps'] ?? [])
        ->map(fn (array $prop): array => collect($prop['listeners'])
            ->flatMap(fn (array $listener): array => array_map(
                fn (string $event): string => "{$listener['channel']['type']}:{$listener['channel']['name']} {$event}",
                $listener['events'],
            ))
            ->all())
        ->all();
}
