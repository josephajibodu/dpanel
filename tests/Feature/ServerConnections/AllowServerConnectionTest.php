<?php

use App\Actions\ServerConnections\AllowServerConnection;
use App\Models\DatabaseUser;
use App\Models\Server;

it('opens MySQL to the client and gives each database user an account other servers can use', function () {
    $host = Server::factory()->database()->create(['database_type' => 'mysql']);
    DatabaseUser::factory()->create(['server_id' => $host->id, 'username' => 'app', 'password' => 'user-secret', 'databases' => ['app_db'], 'permission' => 'readwrite']);
    $connection = connectionTo($host);
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);

    app(AllowServerConnection::class)->execute($connection);

    expect(implode("\n", $commands))
        ->toContain('sudo tee '.AllowServerConnection::MYSQL_NETWORK_CONFIG.' > /dev/null && sudo systemctl restart mysql')
        ->toContain("sudo ufw allow from 10.10.0.3 to any port 3306 proto tcp comment 'flitops-connection-{$connection->id}'")
        ->not->toContain('user-secret')
        ->not->toContain('root-secret');
    expect($uploads['/tmp/tmp.fleetops/statements.sql'])
        ->toContain("CREATE USER IF NOT EXISTS 'app'@'%' IDENTIFIED BY 'user-secret'")
        ->toContain("GRANT ALL PRIVILEGES ON `app_db`.* TO 'app'@'%'");
});

it('opens Postgres to the client through pg_hba.conf', function () {
    $host = Server::factory()->database()->create(['database_type' => 'postgresql']);
    $connection = connectionTo($host);
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads, [
        'SHOW listen_addresses' => "localhost\n",
        'SHOW hba_file' => "/etc/postgresql/16/main/pg_hba.conf\n",
    ]);

    app(AllowServerConnection::class)->execute($connection);

    expect($uploads['/tmp/tmp.fleetops/statements.sql'])->toBe("ALTER SYSTEM SET listen_addresses = '*';\n");
    expect($commands)
        ->toContain('sudo systemctl restart postgresql')
        ->toContain("sudo grep -qF 'flitops-connection-{$connection->id}' '/etc/postgresql/16/main/pg_hba.conf' || echo 'host all all 10.10.0.3/32 scram-sha-256 # flitops-connection-{$connection->id}' | sudo tee -a '/etc/postgresql/16/main/pg_hba.conf' > /dev/null")
        ->toContain("sudo ufw allow from 10.10.0.3 to any port 5432 proto tcp comment 'flitops-connection-{$connection->id}'");
});

it('leaves Postgres running when it already listens on every address', function () {
    $host = Server::factory()->database()->create(['database_type' => 'postgresql']);
    $connection = connectionTo($host);
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads, ['SHOW listen_addresses' => "*\n", 'SHOW hba_file' => '/etc/pg_hba.conf']);

    app(AllowServerConnection::class)->execute($connection);

    expect($commands)->not->toContain('sudo systemctl restart postgresql');
});

it('opens Redis on a cache server behind a password kept off the command line', function () {
    $host = Server::factory()->cache()->create();
    $host->queueSelectedServices(withRedis: true);
    $connection = connectionTo($host);
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);

    app(AllowServerConnection::class)->execute($connection);

    $password = $host->credential('redis_password')->value;
    expect($password)->toHaveLength(40);
    expect($uploads['/tmp/tmp.fleetops/staged'])->toContain("bind 0.0.0.0\nprotected-mode yes\nrequirepass {$password}");
    expect(implode("\n", $commands))
        ->toContain("sudo install -o root -g redis -m '0640' '/tmp/tmp.fleetops/staged' '/etc/redis/flitops.conf'")
        ->toContain("sudo ufw allow from 10.10.0.3 to any port 6379 proto tcp comment 'flitops-connection-{$connection->id}'")
        ->not->toContain($password);
});

it('does not open Redis on a server that hosts sites', function () {
    $host = Server::factory()->create();
    $host->queueSelectedServices(withRedis: true);
    $connection = connectionTo($host);
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);

    app(AllowServerConnection::class)->execute($connection);

    expect(implode("\n", $commands))
        ->toContain('port 3306')
        ->not->toContain('port 6379')
        ->not->toContain('redis');
});
