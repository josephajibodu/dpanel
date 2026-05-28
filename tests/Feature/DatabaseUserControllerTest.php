<?php

use App\Enums\ServerStatus;
use App\Jobs\CreateDatabaseUserJob;
use App\Jobs\DestroyDatabaseUserJob;
use App\Jobs\UpdateDatabaseUserJob;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerDatabase;
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

it('stores a new database user and dispatches job', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $db1 = ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'app_db',
    ]);
    $db2 = ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'other_db',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/database-users", [
            'username' => 'myuser',
            'password' => 'secretpassword123',
            'databases' => ['app_db', 'other_db'],
            'host' => 'localhost',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('database_users', [
        'server_id' => $this->server->id,
        'username' => 'myuser',
        'host' => 'localhost',
        'status' => 'pending',
    ]);

    $databaseUser = DatabaseUser::where('server_id', $this->server->id)
        ->where('username', 'myuser')
        ->first();
    expect($databaseUser->databases)->toBe(['app_db', 'other_db']);

    \Illuminate\Support\Facades\Queue::assertPushed(CreateDatabaseUserJob::class);
});

it('validates database user requires at least one database', function () {
    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/database-users", [
            'username' => 'myuser',
            'password' => 'secretpassword123',
            'databases' => [],
        ]);

    $response->assertSessionHasErrors('databases');
    $this->assertDatabaseMissing('database_users', [
        'server_id' => $this->server->id,
        'username' => 'myuser',
    ]);
});

it('validates database names must exist on server', function () {
    ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'valid_db',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/database-users", [
            'username' => 'myuser',
            'password' => 'secretpassword123',
            'databases' => ['valid_db', 'nonexistent_db'],
        ]);

    $response->assertSessionHasErrors('databases.1');
    $this->assertDatabaseMissing('database_users', [
        'server_id' => $this->server->id,
        'username' => 'myuser',
    ]);
});

it('validates username is unique per server', function () {
    ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'app_db',
    ]);
    DatabaseUser::factory()->create([
        'server_id' => $this->server->id,
        'username' => 'existinguser',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/database-users", [
            'username' => 'existinguser',
            'password' => 'secretpassword123',
            'databases' => ['app_db'],
        ]);

    $response->assertSessionHasErrors('username');
    $this->assertDatabaseCount('database_users', 1);
});

it('updates a database user and dispatches job', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $db1 = ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'db1',
    ]);
    $db2 = ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'db2',
    ]);
    $databaseUser = DatabaseUser::factory()->create([
        'server_id' => $this->server->id,
        'username' => 'edituser',
        'databases' => ['db1'],
        'host' => 'localhost',
    ]);

    $response = $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/database-users/{$databaseUser->id}", [
            'databases' => ['db1', 'db2'],
            'host' => '127.0.0.1',
        ]);

    $response->assertRedirect();

    $databaseUser->refresh();
    expect($databaseUser->databases)->toBe(['db1', 'db2'])
        ->and($databaseUser->host)->toBe('127.0.0.1');

    \Illuminate\Support\Facades\Queue::assertPushed(UpdateDatabaseUserJob::class);
});

it('dispatches job to destroy a database user', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $databaseUser = DatabaseUser::factory()->create([
        'server_id' => $this->server->id,
        'username' => 'todelete',
    ]);

    $response = $this->actingAs($this->user)
        ->delete("/{$this->team->slug}/servers/{$this->server->id}/database-users/{$databaseUser->id}");

    $response->assertRedirect();
    \Illuminate\Support\Facades\Queue::assertPushed(DestroyDatabaseUserJob::class);
    $this->assertDatabaseHas('database_users', ['id' => $databaseUser->id]);
});

it('denies destroy when database user belongs to another server', function () {
    $otherServer = Server::factory()->forTeam($this->team)->create();
    $databaseUser = DatabaseUser::factory()->create([
        'server_id' => $otherServer->id,
        'username' => 'otheruser',
    ]);

    $response = $this->actingAs($this->user)
        ->delete("/{$this->team->slug}/servers/{$this->server->id}/database-users/{$databaseUser->id}");

    $response->assertNotFound();
    $this->assertDatabaseHas('database_users', ['id' => $databaseUser->id]);
});
