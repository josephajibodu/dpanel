<?php

use App\Enums\ServerStatus;
use App\Jobs\CreateServerDatabaseJob;
use App\Jobs\DestroyServerDatabaseJob;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = Server::factory()->create([
        'user_id' => $this->user->id,
        'status' => ServerStatus::Active,
    ]);
});

it('shows databases index for the server', function () {
    ServerDatabase::factory()->count(2)->create(['server_id' => $this->server->id]);

    $response = $this->actingAs($this->user)
        ->get("/servers/{$this->server->id}/databases");

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
    $response = $this->get("/servers/{$this->server->id}/databases");

    $response->assertRedirect();
});

it('denies access to databases index for another users server', function () {
    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)
        ->get("/servers/{$this->server->id}/databases");

    $response->assertForbidden();
});

it('stores a new database and dispatches job', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/databases", [
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

    \Illuminate\Support\Facades\Queue::assertPushed(CreateServerDatabaseJob::class);
});

it('validates database name is unique per server', function () {
    ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'existing_db',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/databases", [
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
        ->delete("/servers/{$this->server->id}/databases/{$database->id}");

    $response->assertRedirect();
    \Illuminate\Support\Facades\Queue::assertPushed(DestroyServerDatabaseJob::class);
    $this->assertDatabaseHas('server_databases', ['id' => $database->id]);
});

it('denies destroy when database belongs to another server', function () {
    $otherServer = Server::factory()->create(['user_id' => $this->user->id]);
    $database = ServerDatabase::factory()->create([
        'server_id' => $otherServer->id,
        'name' => 'other_db',
    ]);

    $response = $this->actingAs($this->user)
        ->delete("/servers/{$this->server->id}/databases/{$database->id}");

    $response->assertNotFound();
    $this->assertDatabaseHas('server_databases', ['id' => $database->id]);
});
