<?php

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = Server::factory()->create([
        'user_id' => $this->user->id,
        'status' => ServerStatus::Active,
    ]);
});

it('stores a new worker', function () {
    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/workers", [
            'name' => 'queue-worker',
            'command' => 'php artisan queue:work',
            'site_id' => null,
            'user' => 'deploy',
            'numprocs' => 2,
            'auto_start' => true,
            'auto_restart' => true,
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('workers', [
        'server_id' => $this->server->id,
        'name' => 'queue-worker',
        'command' => 'php artisan queue:work',
        'user' => 'deploy',
        'numprocs' => 2,
        'auto_start' => true,
        'auto_restart' => true,
        'status' => 'stopped',
    ]);
});

it('validates worker name and command are required', function () {
    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/workers", [
            'name' => '',
            'command' => '',
            'user' => 'deploy',
            'numprocs' => 1,
        ]);

    $response->assertSessionHasErrors(['name', 'command']);
    $this->assertDatabaseCount('workers', 0);
});

it('validates worker site_id belongs to server', function () {
    $otherServer = Server::factory()->create(['user_id' => $this->user->id]);
    $site = \App\Models\Site::factory()->create([
        'server_id' => $otherServer->id,
    ]);

    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/workers", [
            'name' => 'worker',
            'command' => 'php artisan queue:work',
            'site_id' => $site->id,
            'user' => 'deploy',
            'numprocs' => 1,
        ]);

    $response->assertSessionHasErrors('site_id');
    $this->assertDatabaseCount('workers', 0);
});

it('updates a worker', function () {
    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'old-name',
        'command' => 'old command',
        'numprocs' => 1,
    ]);

    $response = $this->actingAs($this->user)
        ->put("/servers/{$this->server->id}/workers/{$worker->id}", [
            'name' => 'new-name',
            'command' => 'php artisan queue:work --sleep=3',
            'site_id' => null,
            'user' => 'www-data',
            'numprocs' => 4,
            'auto_start' => false,
            'auto_restart' => true,
        ]);

    $response->assertRedirect();

    $worker->refresh();
    expect($worker->name)->toBe('new-name')
        ->and($worker->command)->toBe('php artisan queue:work --sleep=3')
        ->and($worker->user)->toBe('www-data')
        ->and($worker->numprocs)->toBe(4)
        ->and($worker->auto_start)->toBeFalse();
});

it('destroys a worker', function () {
    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'to_delete',
    ]);

    $response = $this->actingAs($this->user)
        ->delete("/servers/{$this->server->id}/workers/{$worker->id}");

    $response->assertRedirect();
    $this->assertDatabaseMissing('workers', ['id' => $worker->id]);
});

it('starts a worker', function () {
    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'status' => 'stopped',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/workers/{$worker->id}/start");

    $response->assertRedirect();
    $worker->refresh();
    expect($worker->status)->toBe('active');
});

it('stops a worker', function () {
    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/workers/{$worker->id}/stop");

    $response->assertRedirect();
    $worker->refresh();
    expect($worker->status)->toBe('stopped');
});

it('restarts a worker', function () {
    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'status' => 'stopped',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/workers/{$worker->id}/restart");

    $response->assertRedirect();
    $worker->refresh();
    expect($worker->status)->toBe('active');
});

it('returns worker logs', function () {
    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'stdout_logfile' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->get("/servers/{$this->server->id}/workers/{$worker->id}/logs");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect(str_contains($response->getContent(), 'Log file not available') || str_contains($response->getContent(), 'not set'))->toBeTrue();
});

it('denies access to worker from another server', function () {
    $otherServer = Server::factory()->create(['user_id' => $this->user->id]);
    $worker = Worker::factory()->create(['server_id' => $otherServer->id]);

    $response = $this->actingAs($this->user)
        ->delete("/servers/{$this->server->id}/workers/{$worker->id}");

    $response->assertNotFound();
    $this->assertDatabaseHas('workers', ['id' => $worker->id]);
});
