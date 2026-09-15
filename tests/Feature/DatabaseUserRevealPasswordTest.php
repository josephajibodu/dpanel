<?php

use App\Enums\ServerStatus;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);
});

it('reveals the decrypted password for a database user', function () {
    $databaseUser = DatabaseUser::factory()->create([
        'server_id' => $this->server->id,
        'password' => 'super-secret-password',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/{$this->team->slug}/servers/{$this->server->id}/database-users/{$databaseUser->id}/reveal-password");

    $response->assertOk()
        ->assertJson(['password' => 'super-secret-password']);
});

it('denies guest access to reveal a database user password', function () {
    $databaseUser = DatabaseUser::factory()->create(['server_id' => $this->server->id]);

    $response = $this->postJson("/{$this->team->slug}/servers/{$this->server->id}/database-users/{$databaseUser->id}/reveal-password");

    $response->assertUnauthorized();
});

it('denies revealing a password for another users server', function () {
    $otherUser = User::factory()->create();
    $databaseUser = DatabaseUser::factory()->create(['server_id' => $this->server->id]);

    $response = $this->actingAs($otherUser)
        ->postJson("/{$this->team->slug}/servers/{$this->server->id}/database-users/{$databaseUser->id}/reveal-password");

    $response->assertForbidden();
});

it('404s when the database user belongs to a different server', function () {
    $otherServer = Server::factory()->forTeam($this->team)->create();
    $databaseUser = DatabaseUser::factory()->create(['server_id' => $otherServer->id]);

    $response = $this->actingAs($this->user)
        ->postJson("/{$this->team->slug}/servers/{$this->server->id}/database-users/{$databaseUser->id}/reveal-password");

    $response->assertNotFound();
});
