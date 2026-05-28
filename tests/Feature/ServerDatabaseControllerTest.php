<?php

use App\Enums\ServerStatus;
use App\Jobs\CreateDatabaseUserJob;
use App\Jobs\CreateServerDatabaseJob;
use App\Jobs\DestroyServerDatabaseJob;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);
});

it('shows databases index for the server', function () {
    ServerDatabase::factory()->count(2)->create(['server_id' => $this->server->id]);

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/databases");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('servers/databases/index')
            ->has('server')
            ->has('serverIsReady')
            ->has('databases.data')
            ->has('databaseUsers.data')
            ->where('databases.data', fn ($data) => count($data) === 2)
        );
});

it('denies guest access to databases index', function () {
    $response = $this->get("/{$this->team->slug}/servers/{$this->server->id}/databases");

    $response->assertRedirect();
});

it('denies access to databases index for another users server', function () {
    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/databases");

    $response->assertForbidden();
});

it('stores a new database and creates a default database user', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/databases", [
            'name' => 'myapp_db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('server_databases', [
        'server_id' => $this->server->id,
        'name' => 'myapp_db',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'status' => 'pending',
    ]);

    $this->assertDatabaseHas('database_users', [
        'server_id' => $this->server->id,
        'username' => config('server.user'),
    ]);

    Queue::assertPushed(CreateServerDatabaseJob::class);
    Queue::assertPushed(CreateDatabaseUserJob::class);
});

it('stores a new database with an explicit database user', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/databases", [
            'name' => 'custom_db',
            'db_user' => 'custom_user',
            'db_password' => 'secret_password_123',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('server_databases', [
        'server_id' => $this->server->id,
        'name' => 'custom_db',
        'status' => 'pending',
    ]);

    $this->assertDatabaseHas('database_users', [
        'server_id' => $this->server->id,
        'username' => 'custom_user',
    ]);

    $dbUser = DatabaseUser::where('server_id', $this->server->id)
        ->where('username', 'custom_user')
        ->first();

    expect($dbUser->databases)->toContain('custom_db');

    Queue::assertPushed(CreateServerDatabaseJob::class);
    Queue::assertPushed(CreateDatabaseUserJob::class);
});

it('reuses existing database user when creating a second database', function () {
    Queue::fake();

    $existingUser = DatabaseUser::factory()->create([
        'server_id' => $this->server->id,
        'username' => config('server.user'),
        'databases' => ['first_db'],
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/databases", [
            'name' => 'second_db',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseCount('database_users', 1);

    $existingUser->refresh();
    expect($existingUser->databases)->toContain('first_db')
        ->toContain('second_db');
});

it('requires db_password when db_user is provided', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/databases", [
            'name' => 'test_db',
            'db_user' => 'some_user',
        ]);

    $response->assertSessionHasErrors('db_password');
});

it('validates database name is unique per server', function () {
    ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'existing_db',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/databases", [
            'name' => 'existing_db',
        ]);

    $response->assertSessionHasErrors('name');
    $this->assertDatabaseCount('server_databases', 1);
});

it('dispatches job to destroy a database', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $database = ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'to_delete',
    ]);

    $response = $this->actingAs($this->user)
        ->delete("/{$this->team->slug}/servers/{$this->server->id}/databases/{$database->id}");

    $response->assertRedirect();
    \Illuminate\Support\Facades\Queue::assertPushed(DestroyServerDatabaseJob::class);
    $this->assertDatabaseHas('server_databases', ['id' => $database->id]);
});

it('denies destroy when database belongs to another server', function () {
    $otherServer = Server::factory()->forTeam($this->team)->create();
    $database = ServerDatabase::factory()->create([
        'server_id' => $otherServer->id,
        'name' => 'other_db',
    ]);

    $response = $this->actingAs($this->user)
        ->delete("/{$this->team->slug}/servers/{$this->server->id}/databases/{$database->id}");

    $response->assertNotFound();
    $this->assertDatabaseHas('server_databases', ['id' => $database->id]);
});
