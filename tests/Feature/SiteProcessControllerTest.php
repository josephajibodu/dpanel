<?php

use App\Enums\ServerStatus;
use App\Models\CronJob;
use App\Models\Server;
use App\Models\Site;
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
    $this->site = Site::factory()->forServer($this->server)->create();
});

it('shows site processes index scoped to this site only', function () {
    $otherSite = Site::factory()->forServer($this->server)->create();

    Worker::factory()->count(2)->create([
        'server_id' => $this->server->id,
        'site_id' => $this->site->id,
    ]);
    Worker::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => $otherSite->id,
    ]);
    Worker::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => null,
    ]);

    CronJob::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => $this->site->id,
    ]);
    CronJob::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => $otherSite->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/processes");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sites/processes/index')
            ->has('server')
            ->has('site')
            ->has('serverIsReady')
            ->has('workers.data', 2)
            ->has('cronJobs.data', 1)
        );
});

it('returns 404 when the site does not belong to the server in the URL', function () {
    $otherServer = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$otherServer->id}/sites/{$this->site->id}/processes");

    $response->assertNotFound();
});

it('denies guest access to site processes index', function () {
    $response = $this->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/processes");

    $response->assertRedirect();
});

it('denies access to site processes for a non-member', function () {
    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/processes");

    $response->assertForbidden();
});
