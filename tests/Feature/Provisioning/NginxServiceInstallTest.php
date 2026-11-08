<?php

use App\Contracts\Provisioning\PackageManager;
use App\Contracts\Provisioning\ServiceManager;
use App\Contracts\Remote\RemoteCommandRunner;
use App\Contracts\Remote\RemoteFilesystem;
use App\Models\Server;
use App\Services\Provisioning\NginxService;
use App\Services\Provisioning\ProvisioningContext;

it('ensures the ssl-cert package is installed so the catch-all vhost has a certificate to serve', function () {
    $packages = Mockery::mock(PackageManager::class);
    $packages->shouldReceive('ensureInstalled')->once()->with('nginx');
    $packages->shouldReceive('ensureInstalled')->once()->with('ssl-cert');

    $services = Mockery::mock(ServiceManager::class);
    $services->shouldReceive('enable')->once()->with('nginx');
    $services->shouldReceive('restart')->once()->with('nginx');

    $files = Mockery::mock(RemoteFilesystem::class);
    $files->shouldReceive('backup')->once();
    $files->shouldReceive('ensureDirectory')->times(3);
    $files->shouldReceive('exists')->andReturn(false);
    $files->shouldReceive('put')->times(3);
    $files->shouldReceive('put')->once()->with(NginxService::UPLOAD_LIMIT_CONFIG, "client_max_body_size 8M;\n");
    $files->shouldReceive('symlink')->once();

    $context = new ProvisioningContext(
        server: Server::factory()->make(['php_version' => '8.3']),
        runner: Mockery::mock(RemoteCommandRunner::class),
        files: $files,
        packages: $packages,
        services: $services,
        serverUser: 'artisan',
        sudoPassword: '',
        databasePassword: '',
    );

    app(NginxService::class)->install($context);
});

it('uploads the no-site page into the catch-all web root', function () {
    $files = Mockery::mock(RemoteFilesystem::class)->shouldIgnoreMissing();
    $files->shouldReceive('ensureDirectory')->once()->with('/var/www/flitops')->ordered();
    $files->shouldReceive('put')->once()->with('/var/www/flitops/index.html', NginxService::noSitePage())->ordered();

    $context = new ProvisioningContext(
        server: Server::factory()->make(),
        runner: Mockery::mock(RemoteCommandRunner::class),
        files: $files,
        packages: Mockery::mock(PackageManager::class)->shouldIgnoreMissing(),
        services: Mockery::mock(ServiceManager::class)->shouldIgnoreMissing(),
        serverUser: 'artisan',
        sudoPassword: '',
        databasePassword: '',
    );

    app(NginxService::class)->install($context);
});

it('prints the requested host on the no-site page only through nginx\'s html-escaped echo', function () {
    $page = NginxService::noSitePage();

    expect($page)
        ->toContain('<!--# echo var="host" -->')
        ->not->toContain('encoding=');
});
