<?php

use App\Enums\ServerStatus;
use App\Enums\SiteDomainType;
use App\Models\Certificate;
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
        'hostname' => 'shop.example.com',
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
            ->has('sslAlerts', 1)
            ->where('sslAlerts.0.hostname', 'shop.example.com')
            ->where('sslAlerts.0.ssl_status', 'expiring_soon')
            ->where('sslAlerts.0.site.id', $site->id)
            ->where('sslAlerts.0.site.server_id', $server->id)
            ->has('recentServers.data', 1)
            ->has('recentDeployments', 2));
});

it('lists which domains need ssl attention and leaves healthy ones out', function () {
    $server = Server::factory()->forTeam($this->team)->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'expired.example.com',
        'ssl_expires_at' => now()->subDay(),
    ]);
    SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'healthy.example.com',
        'ssl_expires_at' => now()->addDays(60),
    ]);

    $otherTeam = Team::factory()->forUser(User::factory()->create())->create();
    $otherSite = Site::factory()->create(['server_id' => Server::factory()->forTeam($otherTeam)->create()->id]);
    SiteDomain::factory()->for($otherSite)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'not-mine.example.com',
        'ssl_expires_at' => now()->subDay(),
    ]);

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.ssl_alerts', 1)
            ->has('sslAlerts', 1)
            ->where('sslAlerts.0.hostname', 'expired.example.com')
            ->where('sslAlerts.0.ssl_status', 'expired'));
});

it('leaves the shared free-domain certificate out of the team\'s ssl alerts', function () {
    $server = Server::factory()->forTeam($this->team)->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::System,
        'hostname' => 'app.'.config('server.free_domain'),
    ]);
    Certificate::factory()->create([
        'domain' => '*.'.config('server.free_domain'),
        'expires_at' => now()->addDays(3),
    ]);

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.ssl_alerts', 0)
            ->where('sslAlerts', []));
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
