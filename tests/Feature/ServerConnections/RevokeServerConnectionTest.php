<?php

use App\Actions\ServerConnections\RevokeServerConnection;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerConnection;

it('closes the firewall and removes the Postgres access line', function () {
    $host = Server::factory()->database()->create(['database_type' => 'postgresql']);
    $connection = connectionTo($host);
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads, ['SHOW hba_file' => "/etc/pg_hba.conf\n"]);

    app(RevokeServerConnection::class)->execute($connection);

    expect($commands)
        ->toContain('sudo ufw delete allow from 10.10.0.3 to any port 5432 proto tcp || true')
        ->toContain("sudo sed -i '/# flitops-connection-{$connection->id}\$/d' '/etc/pg_hba.conf'")
        ->toContain('sudo systemctl reload postgresql');
    $this->assertModelMissing($connection);
});

it('drops the MySQL accounts other servers used once the last connection goes', function () {
    $host = Server::factory()->database()->create(['database_type' => 'mysql']);
    DatabaseUser::factory()->create(['server_id' => $host->id, 'username' => 'app']);
    $connection = connectionTo($host);
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);

    app(RevokeServerConnection::class)->execute($connection);

    expect($uploads['/tmp/tmp.fleetops/statements.sql'])->toBe("DROP USER IF EXISTS 'app'@'%';\nFLUSH PRIVILEGES;\n");
});

it('keeps the MySQL accounts while other servers are still connected', function () {
    $host = Server::factory()->database()->create(['database_type' => 'mysql']);
    DatabaseUser::factory()->create(['server_id' => $host->id, 'username' => 'app']);
    $connection = connectionTo($host);
    ServerConnection::factory()->create(['server_id' => $host->id, 'client_server_id' => Server::factory()->web()->create()->id]);
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);

    app(RevokeServerConnection::class)->execute($connection);

    expect($uploads)->not->toHaveKey('/tmp/tmp.fleetops/statements.sql');
    $this->assertModelMissing($connection);
});
