<?php

use App\Enums\SiteDomainType;
use App\Enums\WwwRedirect;
use App\Jobs\SyncSiteNginxJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
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
        'ip_address' => '203.0.113.10',
    ]);
    $this->site = Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'app.flitops.xyz',
    ]);
});

it('shows domains page', function () {
    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sites/domains/index')
            ->has('domains.data')
            ->has('freeDomain'));
});

it('validates a new hostname', function () {
    $response = $this->actingAs($this->user)
        ->postJson("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains/validate", [
            'hostname' => 'brand-new-domain.com',
        ]);

    $response->assertOk()->assertJson(['valid' => true]);
});

it('rejects duplicate hostname on another site', function () {
    $other = Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'other.flitops.xyz',
    ]);
    SiteDomain::factory()
        ->for($other)
        ->create([
            'hostname' => 'taken.com',
            'type' => SiteDomainType::Custom,
            'is_primary' => true,
        ]);

    $response = $this->actingAs($this->user)
        ->postJson("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains/validate", [
            'hostname' => 'taken.com',
        ]);

    $response->assertStatus(422);
});

it('stores a custom domain and dispatches nginx sync', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains", [
            'hostname' => 'my-custom.com',
            'wildcard_enabled' => false,
            'www_redirect' => WwwRedirect::FromWww->value,
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('site_domains', [
        'site_id' => $this->site->id,
        'hostname' => 'my-custom.com',
        'type' => SiteDomainType::Custom->value,
    ]);

    Queue::assertPushed(SyncSiteNginxJob::class);
});

it('sets primary domain', function () {
    Queue::fake();

    $custom = SiteDomain::factory()
        ->for($this->site)
        ->notPrimary()
        ->create([
            'hostname' => 'primary-target.com',
            'type' => SiteDomainType::Custom,
        ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains/{$custom->ulid}/primary");

    $response->assertRedirect();

    $this->site->refresh();
    expect($this->site->domain)->toBe('primary-target.com');
    expect($custom->fresh()->is_primary)->toBeTrue();

    Queue::assertPushed(SyncSiteNginxJob::class);
});
