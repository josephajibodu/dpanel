<?php

use App\Actions\ServerPhp\UpdatePhpSettings;
use App\Enums\ConnectionStatus;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Provisioning\NginxService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function phpSettingsServer(string $webServer): Server
{
    return Server::factory()->create([
        'status' => ServerStatus::Active,
        'connection_status' => ConnectionStatus::Successful,
        'php_version' => '8.3',
        'web_server' => $webServer,
    ]);
}

it('raises the nginx upload limit to match post_max_size', function () {
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads, ['cat /etc/php/8.3/fpm/php.ini' => "post_max_size = 8M\n"]);

    app(UpdatePhpSettings::class)->execute(phpSettingsServer('nginx'), ['post_max_size' => '64M']);

    expect(implode("\n", $commands))
        ->toContain(NginxService::UPLOAD_LIMIT_CONFIG)
        ->toContain('nginx -t');
    expect(end($uploads))->toEndWith("client_max_body_size 64M;\n");
});

it('leaves the web server alone when post_max_size is not changed', function () {
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads, ['cat /etc/php/8.3/fpm/php.ini' => "memory_limit = 128M\n"]);

    app(UpdatePhpSettings::class)->execute(phpSettingsServer('nginx'), ['memory_limit' => '256M']);

    expect(implode("\n", $commands))->not->toContain(NginxService::UPLOAD_LIMIT_CONFIG);
});

it('does not write an nginx limit on caddy servers', function () {
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads, ['cat /etc/php/8.3/fpm/php.ini' => "post_max_size = 8M\n"]);

    app(UpdatePhpSettings::class)->execute(phpSettingsServer('caddy'), ['post_max_size' => '64M']);

    expect(implode("\n", $commands))->not->toContain(NginxService::UPLOAD_LIMIT_CONFIG);
});
