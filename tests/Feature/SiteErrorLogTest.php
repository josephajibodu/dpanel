<?php

use App\Enums\ConnectionStatus;
use App\Enums\NginxFileSection;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * A fake SSH connection that records every command and answers `tail` with
 * the given log output.
 *
 * @param  array<int, string>  $commands
 */
function errorLogConnection(array &$commands, string $logOutput = ''): SshConnection
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $logOutput) {
        $commands[] = $command;

        return match (true) {
            str_contains($command, 'nginx -t') => 'syntax is ok',
            str_contains($command, 'tail -n') => $logOutput,
            default => '',
        };
    });
    $connection->shouldReceive('disconnect')->andReturnNull();

    return $connection;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
        'connection_status' => ConnectionStatus::Successful,
    ]);
    $this->site = Site::factory()->forServer($this->server)->create();
});

it('points every domain at the site\'s own error log', function () {
    $commands = [];
    $this->server->webServer()->syncSite($this->site, errorLogConnection($commands));

    expect($this->site->nginxFiles()->where('section', NginxFileSection::Main->value)->value('content'))
        ->toContain("error_log /var/log/nginx/{$this->site->ulid}-error.log;");
});

it('shows the last lines of the site\'s error log', function () {
    $commands = [];
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn(errorLogConnection($commands, "first error\nupstream sent too big header\n"));
    $this->app->instance(SshService::class, $sshService);

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/logs")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sites/logs/index')
            ->where('webServer', 'Nginx')
            ->where('errorLogAvailable', true)
            ->missing('errorLog')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('errorLog.lines', ['first error', 'upstream sent too big header'])
                ->where('errorLog.failed', false)
            )
        );

    $path = escapeshellarg("/var/log/nginx/{$this->site->ulid}-error.log");

    expect($commands)->toBe(["if sudo test -f {$path}; then sudo tail -n 100 {$path}; fi"]);
});

it('says the error log could not be read when the server is unreachable', function () {
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andThrow(new RuntimeException('Connection refused'));
    $this->app->instance(SshService::class, $sshService);

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/logs")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('errorLog.lines', [])
                ->where('errorLog.failed', true)
            )
        );
});

it('marks the error log unavailable on Caddy without connecting to the server', function () {
    $this->server->update(['web_server' => 'caddy']);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connect');
    $this->app->instance(SshService::class, $sshService);

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/logs")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('webServer', 'Caddy')
            ->where('errorLogAvailable', false)
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('errorLog.lines', [])
            )
        );
});

it('does not show the logs of a site on another team\'s server', function () {
    $otherServer = Server::factory()->forTeam(Team::factory()->forUser(User::factory()->create())->create())->create();
    $otherSite = Site::factory()->forServer($otherServer)->create();

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$otherServer->id}/sites/{$otherSite->id}/logs")
        ->assertNotFound();
});
