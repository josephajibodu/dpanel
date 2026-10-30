<?php

use App\Enums\NginxFileSection;
use App\Exceptions\SshCommandException;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteNginxFile;
use App\Services\Ssh\SshConnection;
use App\Services\WebServer\CaddyWebServer;

/**
 * An SSH connection that records every command and fails the ones the
 * callback says should fail.
 *
 * @param  array<int, string>  $commands
 */
function recordingCaddyConnection(array &$commands, ?Closure $fails = null): SshConnection
{
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $fails) {
        $commands[] = $command;

        if ($fails !== null && $fails($command)) {
            throw new SshCommandException(command: $command, exitCode: 1, output: 'Error: adapting config: unrecognized directive: bogus', stderr: '');
        }

        return '';
    });
    $connection->shouldReceive('disconnect');

    return $connection;
}

beforeEach(function () {
    config(['server.web_user' => 'www-data']);

    $this->server = Server::factory()->create(['web_server' => 'caddy']);
    $this->site = Site::factory()->forServer($this->server)->create(['domain' => 'myapp.com']);
});

it('writes each domain config into the Caddy sites folder, checks it and reloads', function () {
    $commands = [];

    $this->server->webServer()->syncSite($this->site, recordingCaddyConnection($commands));

    $mainFile = $this->site->nginxFiles()->where('section', NginxFileSection::Main->value)->sole();
    expect($mainFile)
        ->path->toBe('myapp-com.conf')
        ->content->toContain('php_fastcgi');
    expect(implode("\n", $commands))
        ->toContain('| sudo tee /etc/caddy/sites/myapp-com.conf > /dev/null')
        ->toContain('sudo -u www-data env XDG_DATA_HOME=/var/lib/caddy/.local/share XDG_CONFIG_HOME=/var/lib/caddy/.config caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile 2>&1')
        ->toContain('sudo /usr/local/sbin/flitops-caddy-reload')
        ->not->toContain('nginx');
});

it('removes the config of a domain the site no longer has', function () {
    SiteNginxFile::factory()->create([
        'site_id' => $this->site->id,
        'section' => NginxFileSection::Main,
        'path' => 'old-domain-com.conf',
    ]);
    $commands = [];

    $this->server->webServer()->syncSite($this->site, recordingCaddyConnection($commands));

    expect($commands)->toContain('sudo rm -f /etc/caddy/sites/old-domain-com.conf');
    $this->assertDatabaseMissing('site_nginx_files', ['site_id' => $this->site->id, 'path' => 'old-domain-com.conf']);
});

it('does not reload when the config is invalid', function () {
    $commands = [];
    $connection = recordingCaddyConnection($commands, fn (string $command) => str_contains($command, 'caddy validate'));

    expect(fn () => $this->server->webServer()->syncSite($this->site, $connection))
        ->toThrow(RuntimeException::class, 'Caddy validation failed: Error: adapting config: unrecognized directive: bogus');
    expect($commands)->not->toContain('sudo /usr/local/sbin/flitops-caddy-reload');
});

it('is the web server for servers set to Caddy', function () {
    expect($this->server->webServer())->toBeInstanceOf(CaddyWebServer::class);
});
