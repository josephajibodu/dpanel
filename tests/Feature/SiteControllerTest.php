<?php

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\SourceControlAccount;
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

it('shows site create page with source control accounts but without prefetched repositories', function () {
    SourceControlAccount::factory()
        ->forUser($this->user)
        ->count(2)
        ->create();

    $response = $this->actingAs($this->user)
        ->get("/servers/{$this->server->id}/sites/create");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sites/create')
            ->has('server')
            ->has('projectTypes')
            ->has('repositoryProviders')
            ->has('phpVersions')
            ->has('sourceControl')
            ->has('sourceControl.accounts')
            ->where('sourceControl.accounts.data', fn ($accounts) => count($accounts) === 2)
            ->missing('sourceControl.repositories')
        );
});
