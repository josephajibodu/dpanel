<?php

use App\Actions\Servers\SyncNginxCatchallVhostAction;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes the catch-all vhost, tests, and reloads nginx', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'php_version' => '8.4']);

    $execCalls = [];
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('exec')
        ->andReturnUsing(function (string $cmd) use (&$execCalls) {
            $execCalls[] = $cmd;

            if (str_contains($cmd, 'nginx -t')) {
                return 'syntax is ok';
            }

            if (str_contains($cmd, 'dpkg -s ssl-cert')) {
                return 'INSTALLED';
            }

            return '';
        });
    $connection->shouldReceive('disconnect')->once();

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->with(Mockery::on(fn ($s) => $s->id === $server->id))->andReturn($connection);
    app()->instance(SshService::class, $sshService);

    app(SyncNginxCatchallVhostAction::class)->execute($server);

    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, 'dpkg -s ssl-cert')))->toBeTrue();
    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, 'apt-get') && str_contains($c, 'ssl-cert')))->toBeFalse();
    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, 'sudo tee /etc/nginx/sites-available/app.conf')))->toBeTrue();
    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, 'listen 443 ssl default_server')))->toBeTrue();
    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, 'sudo ln -sf /etc/nginx/sites-available/app.conf /etc/nginx/sites-enabled/app.conf')))->toBeTrue();
    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, 'sudo nginx -t')))->toBeTrue();
    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, 'sudo systemctl reload nginx')))->toBeTrue();
});

it('throws and disconnects when the pushed config fails nginx -t', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);

    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('exec')
        ->andReturnUsing(fn (string $cmd) => str_contains($cmd, 'nginx -t') ? 'nginx: [emerg] bad config' : '');
    $connection->shouldReceive('disconnect')->once();

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);
    app()->instance(SshService::class, $sshService);

    expect(fn () => app(SyncNginxCatchallVhostAction::class)->execute($server))
        ->toThrow(RuntimeException::class);
});
