<?php

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);
});

it('shares sites for the current server on site pages', function () {
    $sites = Site::factory()
        ->count(2)
        ->forServer($this->server)
        ->sequence(
            ['domain' => 'alpha.example.com'],
            ['domain' => 'beta.example.com'],
        )
        ->create();

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$sites[0]->id}/domains");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sites', 2)
            ->where('sites.0.domain', 'alpha.example.com')
            ->where('sites.1.domain', 'beta.example.com')
        );
});

it('does not share sites outside of server scoped routes', function () {
    Site::factory()->forServer($this->server)->create();

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sites', [])
        );
});
