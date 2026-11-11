<?php

use App\Actions\ServerPhp\UpdatePhpSettings;
use App\Actions\Servers\SyncNginxCatchallVhostAction;
use App\Actions\Sites\RemoveSiteFromServerAction;
use App\Data\SiteServerFootprint;
use App\Enums\NginxFileSection;
use App\Enums\NginxHistoryEvent;
use App\Exceptions\SshCommandException;
use App\Jobs\ApplySiteNginxFileJob;
use App\Jobs\DeleteSiteDomainJob;
use App\Jobs\DeleteSiteNginxFileJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteNginxFile;
use App\Services\Ssh\SshService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;

/*
 * Every change to a server's web server config writes files and then checks
 * the whole config. Two at once could each fail on the other's half-written
 * file, so only one may run per server at a time.
 */

beforeEach(function () {
    Event::fake();
    Sleep::fake(syncWithCarbon: true);

    $this->server = Server::factory()->create(['name' => 'web-1', 'web_server' => 'nginx', 'php_version' => '8.3']);
    $this->site = Site::factory()->forServer($this->server)->create(['domain' => 'myapp.com']);
});

function configFileOf(Site $site): SiteNginxFile
{
    return SiteNginxFile::factory()
        ->forDomain($site->domains()->firstOrFail())
        ->inSection(NginxFileSection::Server, 'headers.conf')
        ->create(['content' => 'add_header X-Robots-Tag "noindex";']);
}

dataset('changes to the web server config', [
    'a site sync' => [fn (Server $server, Site $site) => $server->webServer()->syncSite($site)],
    'a config file edit' => [fn (Server $server, Site $site) => (new ApplySiteNginxFileJob(configFileOf($site), NginxHistoryEvent::Updated, 'old', null, null))->handle(app(SshService::class))],
    'a config file removal' => [fn (Server $server, Site $site) => (new DeleteSiteNginxFileJob(configFileOf($site), null))->handle(app(SshService::class))],
    'a domain removal' => [fn (Server $server, Site $site) => (new DeleteSiteDomainJob($site->domains()->firstOrFail(), $site))->handle(app(SshService::class))],
    'a site removal' => [fn (Server $server, Site $site) => app(RemoveSiteFromServerAction::class)->execute(app(SshService::class)->connect($server), $server, SiteServerFootprint::fromSite($site))],
    'the no-site page' => [fn (Server $server) => app(SyncNginxCatchallVhostAction::class)->execute($server)],
    'an upload limit change' => [fn (Server $server) => app(UpdatePhpSettings::class)->execute($server, ['post_max_size' => '64M'])],
]);

it('reloads the web server when no other change to the server is running', function (Closure $change) {
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);

    $change($this->server, $this->site);

    expect(implode("\n", $commands))->toContain('systemctl reload nginx');
})->with('changes to the web server config');

it('leaves the web server alone while another change to the server is running', function (Closure $change) {
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);

    $this->server->webServer()->withConfigLock($this->server, function () use ($change): void {
        try {
            $change($this->server, $this->site);
        } catch (RuntimeException) {
            // Some changes report the refusal themselves instead of throwing.
        }
    });

    expect(implode("\n", $commands))
        ->not->toContain('nginx -t')
        ->not->toContain('systemctl reload nginx');
})->with('changes to the web server config');

it('says another change is running after waiting 20 seconds for it', function () {
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);
    $started = now();

    $this->server->webServer()->withConfigLock($this->server, function (): void {
        expect(fn () => $this->server->webServer()->syncSite($this->site))
            ->toThrow(RuntimeException::class, 'Another change to the web server config on web-1 is still running. Try again in a moment.');
    });

    expect($started->diffInSeconds(now()))->toEqualWithDelta(20, 0.5);
});

it('does not wait for a change running on another server', function () {
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);
    $otherServer = Server::factory()->create(['web_server' => 'nginx']);

    $otherServer->webServer()->withConfigLock($otherServer, fn () => $this->server->webServer()->syncSite($this->site));

    expect(implode("\n", $commands))->toContain('systemctl reload nginx');
    Sleep::assertNeverSlept();
});

it('lets the next change run after one fails', function () {
    $webServer = $this->server->webServer();
    $commands = [];
    $rejecting = mockDatabaseConnection(onExec: fn (string $command) => str_contains($command, 'nginx -t')
        ? throw new SshCommandException(command: $command, exitCode: 1, output: 'unknown directive', stderr: '')
        : null);

    expect(fn () => $webServer->syncSite($this->site, $rejecting))
        ->toThrow(RuntimeException::class, 'Nginx validation failed: unknown directive');

    $uploads = [];
    $webServer->syncSite($this->site, mockDatabaseConnection($uploads, $commands));

    expect(implode("\n", $commands))->toContain('systemctl reload nginx');
    Sleep::assertNeverSlept();
});
