<?php

use App\Enums\ServerStatus;
use App\Models\CronJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);
});

it('shows processes index with workers and cron jobs', function () {
    Worker::factory()->count(2)->create(['server_id' => $this->server->id]);
    CronJob::factory()->count(1)->create(['server_id' => $this->server->id]);

    $response = $this->actingAs($this->user)
        ->get("/servers/{$this->server->id}/processes");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('servers/processes/index')
            ->has('server')
            ->has('serverIsReady')
            ->has('workers.data')
            ->has('cronJobs.data')
            ->has('sites')
            ->where('workers.data', fn ($data) => count($data) === 2)
            ->where('cronJobs.data', fn ($data) => count($data) === 1)
        );
});

it('denies guest access to processes index', function () {
    $response = $this->get("/servers/{$this->server->id}/processes");

    $response->assertRedirect();
});

it('denies access to processes index for another users server', function () {
    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)
        ->get("/servers/{$this->server->id}/processes");

    $response->assertForbidden();
});
