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
