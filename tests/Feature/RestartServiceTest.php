<?php

use App\Enums\ServiceType;
use App\Enums\TeamRoleKey;
use App\Events\ServerActionsUpdated;
use App\Jobs\RestartServiceJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
    $this->server = Server::factory()->forTeam($this->team)->active()->create([
        'web_server' => 'nginx',
        'php_version' => '8.3',
        'database_type' => null,
    ]);
});

it('restarts a service the server runs', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post(route('servers.restart', [$this->team, $this->server]), ['service' => 'nginx'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->server->actions()->first())
        ->action->toBe('restart_nginx')
        ->status->toBe('pending');
    Queue::assertPushed(RestartServiceJob::class, fn ($job) => $job->service === ServiceType::Nginx);
});

it('refuses to restart a service the server does not run', function (string $service) {
    Queue::fake();

    $this->actingAs($this->user)
        ->post(route('servers.restart', [$this->team, $this->server]), ['service' => $service])
        ->assertSessionHasErrors('service');

    Queue::assertNothingPushed();
})->with(['caddy', 'mysql', 'redis', 'supervisor']);

it('refuses restarts from members who cannot manage servers', function () {
    Queue::fake();
    $developer = User::factory()->memberOf($this->team, TeamRoleKey::Developer)->create();

    $this->actingAs($developer)
        ->post(route('servers.restart', [$this->team, $this->server]), ['service' => 'nginx'])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('records the result and tells the page', function () {
    Event::fake([ServerActionsUpdated::class]);
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);
    $action = $this->server->actions()->create(['action' => 'restart_nginx', 'status' => 'pending']);

    app()->call([new RestartServiceJob($this->server, ServiceType::Nginx, $action), 'handle']);

    expect($action->fresh()->status)->toBe('finished')
        ->and(implode("\n", $commands))->toContain('systemctl restart nginx');
    Event::assertDispatched(ServerActionsUpdated::class, 2);
});
