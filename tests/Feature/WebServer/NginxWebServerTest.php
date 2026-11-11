<?php

use App\Enums\NginxFileSection;
use App\Exceptions\SshCommandException;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteNginxFile;
use App\Services\Ssh\SshConnection;

/**
 * An SSH connection that records every command and fails the ones the
 * callback says should fail.
 *
 * @param  array<int, string>  $commands
 */
function recordingNginxConnection(array &$commands, ?Closure $fails = null): SshConnection
{
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $fails) {
        $commands[] = $command;

        if ($fails !== null && $fails($command)) {
            throw new SshCommandException(command: $command, exitCode: 1, output: 'nginx: [emerg] unknown directive "bogus"', stderr: '');
        }

        return '';
    });
    $connection->shouldReceive('disconnect');

    return $connection;
}

beforeEach(function () {
    $this->server = Server::factory()->create(['web_server' => 'nginx']);
    $this->site = Site::factory()->forServer($this->server)->create(['domain' => 'myapp.com', 'php_version' => '8.3']);
    $this->domain = $this->site->domains()->firstOrFail();
});

it('writes each domain config, enables it, checks it and reloads', function () {
    $commands = [];

    $this->server->webServer()->syncSite($this->site, recordingNginxConnection($commands));

    expect($commands)
        ->toContain('sudo ln -sf /etc/nginx/sites-available/myapp-com.conf /etc/nginx/sites-enabled/myapp-com.conf')
        ->toContain('sudo nginx -t 2>&1')
        ->toContain('sudo systemctl reload nginx')
        ->and(implode("\n", $commands))->toContain('| sudo tee /etc/nginx/sites-available/myapp-com.conf > /dev/null')
        ->and($this->site->nginxFiles()->where('section', NginxFileSection::Main->value)->sole())
        ->path->toBe('myapp-com.conf')
        ->sync_status->toBe('synced');
});

it('rebuilds the main config from the site\'s current settings on every sync', function () {
    $connectionCommands = [];
    $this->server->webServer()->syncSite($this->site, recordingNginxConnection($connectionCommands));

    $this->site->update(['php_version' => '8.4']);
    $this->server->webServer()->syncSite($this->site->fresh(), recordingNginxConnection($connectionCommands));

    expect($this->site->nginxFiles()->where('section', NginxFileSection::Main->value)->sole()->content)
        ->toContain('php8.4-fpm')
        ->not->toContain('php8.3-fpm');
});

it('removes the config of a domain the site no longer has', function () {
    SiteNginxFile::factory()->forSite($this->site)->inSection(NginxFileSection::Main, 'old-domain-com.conf')->create();
    $commands = [];

    $this->server->webServer()->syncSite($this->site, recordingNginxConnection($commands));

    expect($commands)->toContain('sudo rm -f /etc/nginx/sites-enabled/old-domain-com.conf /etc/nginx/sites-available/old-domain-com.conf');
    $this->assertDatabaseMissing('site_nginx_files', ['site_id' => $this->site->id, 'path' => 'old-domain-com.conf']);
});

it('puts the previous configs back and skips the reload when nginx rejects the new ones', function () {
    $previous = SiteNginxFile::factory()->forDomain($this->domain)->inSection(NginxFileSection::Main, 'myapp-com.conf')->create([
        'content' => '# what the last sync wrote',
    ]);
    $removed = SiteNginxFile::factory()->forSite($this->site)->inSection(NginxFileSection::Main, 'old-domain-com.conf')->create([
        'content' => '# a domain being removed',
    ]);
    $commands = [];
    $connection = recordingNginxConnection($commands, fn (string $command) => str_contains($command, 'nginx -t'));

    expect(fn () => $this->server->webServer()->syncSite($this->site, $connection))
        ->toThrow(RuntimeException::class, 'Nginx validation failed: nginx: [emerg] unknown directive "bogus"');

    $afterCheck = array_slice($commands, array_search('sudo nginx -t 2>&1', $commands, true) + 1);

    expect($afterCheck)
        ->toContain("echo '# what the last sync wrote' | sudo tee /etc/nginx/sites-available/myapp-com.conf > /dev/null")
        ->toContain("echo '# a domain being removed' | sudo tee /etc/nginx/sites-available/old-domain-com.conf > /dev/null")
        ->not->toContain('sudo systemctl reload nginx')
        ->and($previous->fresh()->content)->toBe('# what the last sync wrote')
        ->and($removed->fresh())->not->toBeNull();
});

it('removes a new domain\'s config again when nginx rejects it', function () {
    $commands = [];
    $connection = recordingNginxConnection($commands, fn (string $command) => str_contains($command, 'nginx -t'));

    expect(fn () => $this->server->webServer()->syncSite($this->site, $connection))->toThrow(RuntimeException::class);

    expect(end($commands))->toBe('sudo rm -f /etc/nginx/sites-enabled/myapp-com.conf /etc/nginx/sites-available/myapp-com.conf');
    $this->assertDatabaseMissing('site_nginx_files', ['site_id' => $this->site->id, 'section' => NginxFileSection::Main->value]);
});

it('clears the folders and managed files earlier versions kept beside the user\'s files', function () {
    $snippets = $this->domain->nginxSnippetsBasePath();
    $userFile = SiteNginxFile::factory()->forDomain($this->domain)->inSection(NginxFileSection::Server, 'headers.conf')->create();
    foreach (['before' => 'before/ssl_redirect.conf', 'server' => 'server/error_log.conf'] as $section => $path) {
        DB::table('site_nginx_files')->insert([
            'ulid' => (string) Str::ulid(),
            'site_id' => $this->site->id,
            'site_domain_id' => $this->domain->id,
            'section' => $section,
            'path' => $path,
        ]);
    }
    $commands = [];

    $this->server->webServer()->syncSite($this->site, recordingNginxConnection($commands));

    expect($commands)->toContain(
        "sudo mkdir -p {$snippets}/server {$snippets}/after && sudo rm -rf {$snippets}/before {$snippets}/locations {$snippets}/server/acme_challenge.conf {$snippets}/server/error_log.conf"
    );
    expect($this->site->nginxFiles()->pluck('path')->sort()->values()->all())->toBe(['myapp-com.conf', $userFile->path]);
});
