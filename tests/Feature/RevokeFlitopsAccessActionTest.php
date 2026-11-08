<?php

use App\Actions\Servers\RevokeFlitopsAccessAction;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('removes our key from root and the server user, and nothing else', function () {
    $server = Server::factory()->custom()->create(['ip_address' => '203.0.113.10']);
    ServerCredential::create(['server_id' => $server->id, 'type' => 'public_key', 'value' => 'ssh-ed25519 AAAAOURKEY flitops-worker']);

    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('exec')->once()->withArgs(function (string $command) {
        return str_contains($command, "-v key='AAAAOURKEY'")
            && str_contains($command, "'/root/.ssh/authorized_keys'")
            && str_contains($command, "'/home/".config('server.user')."/.ssh/authorized_keys'");
    });
    $connection->shouldReceive('disconnect')->once();

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connectAsRoot')->once()->andReturn($connection);

    (new RevokeFlitopsAccessAction($sshService))->execute($server);
});

it('does not connect when the server has no key of ours on record', function () {
    $server = Server::factory()->custom()->create(['ip_address' => '203.0.113.10']);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connectAsRoot');

    (new RevokeFlitopsAccessAction($sshService))->execute($server);
});
