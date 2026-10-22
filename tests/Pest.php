<?php

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

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
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
function mockDatabaseConnection(array &$uploads = [], array &$commands = [], ?Closure $onExec = null): App\Services\Ssh\SshConnection
{
    $connection = Mockery::mock(App\Services\Ssh\SshConnection::class)->makePartial();
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
