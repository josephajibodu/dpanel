<?php

use App\Contracts\Provisioning\PackageManager;
use App\Contracts\Provisioning\ServiceManager;
use App\Contracts\Remote\RemoteCommandRunner;
use App\Contracts\Remote\RemoteFilesystem;
use App\Models\Server;
use App\Services\Provisioning\CaddyService;
use App\Services\Provisioning\ProvisioningContext;

beforeEach(function () {
    config(['server.web_user' => 'www-data']);

    $this->files = [];
    $this->commands = [];

    $runner = Mockery::mock(RemoteCommandRunner::class);
    $runner->shouldReceive('runQuietly')->andReturnUsing(fn (string $command) => $this->commands[] = $command);
    $files = Mockery::mock(RemoteFilesystem::class);
    $files->shouldReceive('ensureDirectory');
    $files->shouldReceive('put')->andReturnUsing(fn (string $path, string $contents) => $this->files[$path] = $contents);
    $packages = Mockery::mock(PackageManager::class);
    $packages->shouldReceive('ensureInstalled');
    $this->services = Mockery::mock(ServiceManager::class);

    $this->context = new ProvisioningContext(
        server: Server::factory()->make(['web_server' => 'caddy']),
        runner: $runner,
        files: $files,
        packages: $packages,
        services: $this->services,
        serverUser: 'flitops',
        sudoPassword: '',
        databasePassword: '',
    );
});

it('runs Caddy as the web user and loads every site config', function () {
    $this->services->shouldReceive('enable')->once()->with('caddy');
    $this->services->shouldReceive('restart')->once()->with('caddy');

    app(CaddyService::class)->install($this->context);

    expect($this->files['/etc/systemd/system/caddy.service.d/flitops.conf'])
        ->toContain("User=www-data\nGroup=www-data");
    expect($this->files['/etc/caddy/Caddyfile'])
        ->toContain('import /etc/caddy/sites/*.conf')
        ->toContain("root * /var/www/flitops\n\ttemplates\n\trewrite * /index.html\n\tfile_server {\n\t\tstatus 404\n\t}");
    expect($this->files['/var/www/flitops/index.html'])
        ->toContain('Nothing deployed here.')
        ->toContain('<span class="status-host">{{html .Host}}</span>')
        ->not->toContain('<!--#');
    expect($this->files['/usr/local/sbin/flitops-caddy-reload'])->toContain('id -G www-data');
    expect($this->commands)
        ->toContain('chmod 755 /usr/local/sbin/flitops-caddy-reload')
        ->toContain('mkdir -p /var/lib/caddy/.local/share /var/lib/caddy/.config && chown -R www-data:www-data /var/lib/caddy')
        ->toContain('systemctl daemon-reload');
});
