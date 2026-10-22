<?php

use App\Actions\Database\CreateDatabaseUser;
use App\Actions\Database\DestroyDatabaseUser;
use App\Actions\Database\DestroyServerDatabase;
use App\Actions\Database\QuotesMysql;
use App\Actions\Database\UpdateDatabaseUser;
use App\Enums\ServerStatus;
use App\Jobs\CreateDatabaseUserJob;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\ServerDatabase;
use App\Models\Team;
use App\Models\User;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const SQL_INJECTION_PASSWORD = "p\\'; DROP USER 'root'@'localhost'; -- ";

/**
 * Record every SQL statement the action sends through `mysql -e`, with the
 * shell quoting undone, so tests can assert on exactly what MySQL would parse.
 *
 * @param  array<int, string>  $statements
 */
function recordMysqlStatements(array &$statements): void
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$statements) {
        if (preg_match("/ -e ('(?:[^']|'\\\\'')*')$/", $command, $matches)) {
            $statements[] = str_replace("'\\''", "'", substr($matches[1], 1, -1));
        }

        return '';
    });
    $connection->shouldReceive('disconnect')->andReturn(null);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $sshService);
}

function mysqlServer(): Server
{
    $server = Server::factory()->create(['database_type' => 'mysql', 'status' => ServerStatus::Active]);
    ServerCredential::create(['server_id' => $server->id, 'type' => 'database_password', 'value' => 'rootsecret']);

    return $server;
}

it('quotes mysql string literals so backslashes and quotes cannot end them', function (string $value, string $expected) {
    $quoter = new class
    {
        use QuotesMysql;

        public function quote(string $value): string
        {
            return $this->mysqlString($value);
        }
    };

    expect($quoter->quote($value))->toBe($expected);
})->with([
    'plain' => ['secret', "'secret'"],
    'single quote' => ["it's", "'it\\'s'"],
    'trailing backslash' => ['secret\\', "'secret\\\\'"],
    'backslash before quote' => ["\\'", "'\\\\\\''"],
    'nul byte' => ["a\0b", "'a\\0b'"],
]);

it('quotes mysql identifiers by doubling backticks', function () {
    $quoter = new class
    {
        use QuotesMysql;

        public function quote(string $value): string
        {
            return $this->mysqlIdentifier($value);
        }
    };

    expect($quoter->quote('app`db'))->toBe('`app``db`');
});

it('keeps an injection attempt inside the password literal when creating a user', function () {
    $server = mysqlServer();
    $user = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'username' => 'app',
        'host' => '%',
        'password' => SQL_INJECTION_PASSWORD,
        'databases' => ['app_db'],
        'permission' => 'readwrite',
    ]);

    $statements = [];
    recordMysqlStatements($statements);

    app(CreateDatabaseUser::class)->execute($user);

    expect($statements)->toBe([
        "CREATE USER IF NOT EXISTS 'app'@'%' IDENTIFIED BY 'p\\\\\\'; DROP USER \\'root\\'@\\'localhost\\'; -- '",
        "GRANT ALL PRIVILEGES ON `app_db`.* TO 'app'@'%'",
    ]);
});

it('keeps a trailing backslash inside the password literal when updating a user', function () {
    $server = mysqlServer();
    $user = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'username' => 'app',
        'host' => 'localhost',
        'password' => 'new-secret\\',
        'databases' => ['app_db'],
        'permission' => 'readonly',
    ]);

    $statements = [];
    recordMysqlStatements($statements);

    app(UpdateDatabaseUser::class)->execute($user);

    expect($statements)->toBe([
        "ALTER USER 'app'@'localhost' IDENTIFIED BY 'new-secret\\\\'",
        "REVOKE ALL PRIVILEGES ON *.* FROM 'app'@'localhost'",
        "GRANT SELECT ON `app_db`.* TO 'app'@'localhost'",
    ]);
});

it('quotes the account when dropping a user', function () {
    $server = mysqlServer();
    $user = DatabaseUser::factory()->create([
        'server_id' => $server->id,
        'username' => 'app',
        'host' => '10.0.0.%',
    ]);

    $statements = [];
    recordMysqlStatements($statements);

    app(DestroyDatabaseUser::class)->execute($user);

    expect($statements)->toBe(["DROP USER IF EXISTS 'app'@'10.0.0.%'"]);
});

it('quotes the database name when dropping a database', function () {
    $server = mysqlServer();
    $database = ServerDatabase::factory()->create(['server_id' => $server->id, 'name' => 'app_db']);

    $statements = [];
    recordMysqlStatements($statements);

    app(DestroyServerDatabase::class)->execute($database);

    expect($statements)->toBe(['DROP DATABASE IF EXISTS `app_db`']);
});

describe('database user host validation', function () {
    beforeEach(function () {
        Queue::fake();

        $this->user = User::factory()->create();
        $this->team = Team::factory()->forUser($this->user)->create();
        $this->server = Server::factory()->forTeam($this->team)->create(['status' => ServerStatus::Active]);
        ServerDatabase::factory()->create(['server_id' => $this->server->id, 'name' => 'app_db']);
    });

    it('accepts real mysql host patterns', function (string $host) {
        $this->actingAs($this->user)
            ->post(route('servers.database-users.store', [$this->team, $this->server]), [
                'username' => 'app',
                'password' => 'secretpassword123',
                'databases' => ['app_db'],
                'host' => $host,
            ])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(CreateDatabaseUserJob::class);
    })->with([
        'localhost' => ['localhost'],
        'any host' => ['%'],
        'subnet wildcard' => ['10.0.0.%'],
        'netmask' => ['192.168.1.0/255.255.255.0'],
        'hostname' => ['db-1.internal.example.com'],
        'ipv6' => ['::1'],
    ]);

    it('rejects hosts that could break out of the account literal', function (string $host) {
        $this->actingAs($this->user)
            ->post(route('servers.database-users.store', [$this->team, $this->server]), [
                'username' => 'app',
                'password' => 'secretpassword123',
                'databases' => ['app_db'],
                'host' => $host,
            ])
            ->assertSessionHasErrors('host');

        Queue::assertNotPushed(CreateDatabaseUserJob::class);
    })->with([
        'quote' => ["localhost' OR '1'='1"],
        'backslash' => ['localhost\\'],
        'space' => ['local host'],
        'newline' => ["localhost\nx"],
        'semicolon' => ['localhost;DROP'],
    ]);
});
