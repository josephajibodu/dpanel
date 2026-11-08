<?php

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
});

it('shows the 5 most recent databases on the server page', function () {
    $server = Server::factory()->forTeam($this->team)->active()->create();

    $databases = ServerDatabase::factory()
        ->for($server)
        ->count(6)
        ->sequence(fn ($sequence) => ['created_at' => now()->addSeconds($sequence->index)])
        ->create();

    $mostRecent = $databases->last();
    $oldest = $databases->first();

    $response = $this->actingAs($this->user)
        ->get(route('servers.show', [$this->team, $server]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('servers/show')
        ->has('server.data.databases', 5)
        ->where('server.data.databases.0.name', $mostRecent->name)
        ->where(
            'server.data.databases',
            fn ($databaseNames) => collect($databaseNames)
                ->pluck('name')
                ->doesntContain($oldest->name),
        )
    );
});

it('tells the page whether the server has PHP', function (string $state, bool $hasPhp) {
    $server = Server::factory()->forTeam($this->team)->active()->{$state}()->create();

    $response = $this->actingAs($this->user)
        ->get(route('servers.show', [$this->team, $server]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('server.data.has_php', $hasPhp)
    );
})->with([
    'app server' => ['app', true],
    'worker server' => ['worker', true],
    'database server' => ['database', false],
    'load balancer' => ['loadBalancer', false],
]);

it('tells the page whether the server can host sites', function (string $state, bool $supportsSites) {
    $server = Server::factory()->forTeam($this->team)->active()->{$state}()->create();

    $response = $this->actingAs($this->user)
        ->get(route('servers.show', [$this->team, $server]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('server.data.supports_sites', $supportsSites)
    );
})->with([
    'app server' => ['app', true],
    'web server' => ['web', true],
    'worker server' => ['worker', false],
    'database server' => ['database', false],
]);

it('refreshes the server when its status, its sites or a deployment on it changes', function () {
    $server = Server::factory()->forTeam($this->team)->active()->create();
    $channel = "private:server.{$server->id}";

    $response = $this->actingAs($this->user)
        ->get(route('servers.show', [$this->team, $server]));

    expect(liveProps($response))->toMatchArray([
        'server' => [
            "{$channel} server.status.changed",
            "{$channel} server.sites.updated",
            "{$channel} deployment.status.changed",
        ],
        'services' => ["{$channel} server.actions.updated"],
    ]);
});

it('lists the services the server can restart, with how each last restart went', function () {
    $server = Server::factory()->forTeam($this->team)->active()->create([
        'web_server' => 'nginx',
        'php_version' => '8.3',
        'database_type' => 'mariadb',
    ]);
    $server->actions()->create(['action' => 'restart_nginx', 'status' => 'finished', 'finished_at' => now()->subDay()]);
    $server->actions()->create(['action' => 'restart_nginx', 'status' => 'failed', 'error' => 'Job for nginx.service failed', 'finished_at' => now()]);

    $this->actingAs($this->user)
        ->get(route('servers.show', [$this->team, $server]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('services', fn ($services) => collect($services)->pluck('value')->all() === ['nginx', 'php', 'mysql'])
            ->where('services.0.last_restart.status', 'failed')
            ->where('services.0.last_restart.error', 'Job for nginx.service failed')
            ->where('services.1.last_restart', null)
        );
});
