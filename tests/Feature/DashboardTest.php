<?php

use App\Enums\ServerStatus;
use App\Enums\SiteDomainType;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
});

it('shows an empty state when the team has no servers', function () {
    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/dashboard");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('stats.servers', 0)
            ->where('recentServers.data', [])
            ->where('recentDeployments', []));
});

it('shows real stats and recent activity for a team with servers and deployments', function () {
    $server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);
    $site = Site::factory()->create(['server_id' => $server->id]);
    SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'verified_at' => now(),
        'ssl_expires_at' => now()->addDays(5),
    ]);
    Deployment::factory()->forSite($site)->count(2)->create();

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/dashboard");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('stats.servers', 1)
            ->where('stats.active_servers', 1)
            ->where('stats.sites', 1)
            ->where('stats.deployments_this_week', 2)
            ->where('stats.ssl_alerts', 1)
            ->has('recentServers.data', 1)
            ->has('recentDeployments', 2));
});

it('requires authentication', function () {
    $response = $this->get("/{$this->team->slug}/dashboard");

    $response->assertRedirect('/login');
});

it('requires team membership', function () {
    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->forUser($otherUser)->create();

    $response = $this->actingAs($this->user)
        ->get("/{$otherTeam->slug}/dashboard");

    $response->assertForbidden();
});

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('legacy dashboard url redirects to the current team dashboard', function () {
    $this->user->switchTeam($this->team);

    $this->actingAs($this->user)
        ->get('/dashboard')
        ->assertRedirect(route('dashboard', $this->team));
});
