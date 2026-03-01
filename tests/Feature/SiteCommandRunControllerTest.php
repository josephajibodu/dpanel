<?php

use App\Enums\ServerStatus;
use App\Jobs\RunSiteCommandJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCommandRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = Server::factory()->create([
        'user_id' => $this->user->id,
        'status' => ServerStatus::Active,
    ]);
    $this->site = Site::factory()->create([
        'server_id' => $this->server->id,
    ]);
});

it('shows command runs index for the site', function () {
    SiteCommandRun::factory()->count(2)->forSite($this->site)->create();

    $response = $this->actingAs($this->user)
        ->get("/servers/{$this->server->id}/sites/{$this->site->id}/command-runs");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('sites/commands/index')
        ->has('server')
        ->has('site')
        ->has('commandRuns')
        ->has('commandRuns.data')
        ->where('commandRuns.data', fn ($data) => count($data) === 2)
    );
});

it('denies guest access to command runs index', function () {
    $response = $this->get("/servers/{$this->server->id}/sites/{$this->site->id}/command-runs");

    $response->assertRedirect();
});

it('denies access to command runs for another users site', function () {
    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)
        ->get("/servers/{$this->server->id}/sites/{$this->site->id}/command-runs");

    $response->assertForbidden();
});

it('stores a new command run and dispatches job', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/sites/{$this->site->id}/command-runs", [
            'command' => 'php artisan --version',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('site_command_runs', [
        'site_id' => $this->site->id,
        'command' => 'php artisan --version',
        'status' => 'pending',
    ]);

    Queue::assertPushed(RunSiteCommandJob::class);
});

it('validates command is required', function () {
    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/sites/{$this->site->id}/command-runs", [
            'command' => '',
        ]);

    $response->assertSessionHasErrors('command');
    $this->assertDatabaseCount('site_command_runs', 0);
});

it('validates command max length', function () {
    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/sites/{$this->site->id}/command-runs", [
            'command' => str_repeat('x', 1025),
        ]);

    $response->assertSessionHasErrors('command');
    $this->assertDatabaseCount('site_command_runs', 0);
});

it('show returns run with output for modal', function () {
    $run = SiteCommandRun::factory()->forSite($this->site)->create([
        'output' => 'Laravel Framework 12.x',
        'exit_code' => 0,
    ]);

    $response = $this->actingAs($this->user)
        ->get("/servers/{$this->server->id}/sites/{$this->site->id}/command-runs/{$run->id}", [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

    $response->assertOk();
    $response->assertJsonPath('id', $run->id);
    $response->assertJsonPath('output', 'Laravel Framework 12.x');
});

it('show returns 404 when command run belongs to another site', function () {
    $otherSite = Site::factory()->create([
        'server_id' => $this->server->id,
    ]);
    $run = SiteCommandRun::factory()->forSite($otherSite)->create();

    $response = $this->actingAs($this->user)
        ->get("/servers/{$this->server->id}/sites/{$this->site->id}/command-runs/{$run->id}", [
            'Accept' => 'application/json',
        ]);

    $response->assertNotFound();
});
