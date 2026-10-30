<?php

use App\Enums\NginxFileSection;
use App\Enums\NginxHistoryEvent;
use App\Exceptions\SshCommandException;
use App\Jobs\ApplySiteNginxFileJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteNginxFile;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();

    $this->server = Server::factory()->create(['web_server' => 'caddy']);
    $this->site = Site::factory()->forServer($this->server)->create(['domain' => 'myapp.com']);
    $this->file = SiteNginxFile::factory()->forDomain($this->site->domains()->firstOrFail())->inSection(NginxFileSection::Server, 'headers.conf')->create([
        'content' => 'header X-Robots-Tag "noindex"',
    ]);
    $this->snippetPath = $this->file->absolutePath();
});

/**
 * @param  array<int, string>  $commands
 */
function fakeSshForApplyJob(array &$commands, bool $configIsValid): void
{
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $configIsValid) {
        $commands[] = $command;

        if (! $configIsValid && str_contains($command, 'caddy validate')) {
            throw new SshCommandException(command: $command, exitCode: 1, output: 'unrecognized directive', stderr: '');
        }

        return '';
    });
    $connection->shouldReceive('disconnect');

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $sshService);
}

it('writes an edited snippet into the Caddy snippet folder, checks it and reloads Caddy', function () {
    $commands = [];
    fakeSshForApplyJob($commands, configIsValid: true);

    (new ApplySiteNginxFileJob($this->file, NginxHistoryEvent::Updated, 'old', null, null))->handle(app(SshService::class));

    expect($this->snippetPath)->toStartWith('/etc/caddy/flitops-conf/');
    expect(implode("\n", $commands))
        ->toContain("| sudo tee {$this->snippetPath} > /dev/null")
        ->toContain('caddy validate --config /etc/caddy/Caddyfile')
        ->toContain('sudo /usr/local/sbin/flitops-caddy-reload')
        ->not->toContain('nginx -t');
    expect($this->file->fresh()->sync_status)->toBe('synced');
});

it('puts the previous snippet back and skips the reload when Caddy rejects the edit', function () {
    $commands = [];
    fakeSshForApplyJob($commands, configIsValid: false);

    expect(fn () => (new ApplySiteNginxFileJob($this->file, NginxHistoryEvent::Updated, 'old', null, null))->handle(app(SshService::class)))
        ->toThrow(RuntimeException::class, 'Caddy validation failed: unrecognized directive');

    expect($commands)
        ->toContain("sudo mv -f {$this->snippetPath}.flitops-bak {$this->snippetPath} 2>/dev/null || true")
        ->not->toContain('sudo /usr/local/sbin/flitops-caddy-reload');
    expect($this->file->fresh())
        ->sync_status->toBe('failed')
        ->sync_error->toBe('Caddy validation failed: unrecognized directive');
});
