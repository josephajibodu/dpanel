<?php

use App\Enums\SiteDomainType;
use App\Jobs\IssueSslForDomainJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

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

it('dispatches IssueSslForDomainJob for a verified custom domain', function () {
    Queue::fake();

    $domain = SiteDomain::factory()->for($this->site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'renew-me.com',
        'verified_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains/{$domain->ulid}/ssl/renew");

    $response->assertRedirect();
    expect(session('success'))->toContain('Renewing SSL certificate');

    Queue::assertPushed(IssueSslForDomainJob::class, fn ($job) => $job->siteDomain->id === $domain->id);
});

it('returns error and does not dispatch for an unverified custom domain', function () {
    Queue::fake();

    $domain = SiteDomain::factory()->for($this->site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'unverified.com',
        'verified_at' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains/{$domain->ulid}/ssl/renew");

    $response->assertRedirect();
    expect(session('error'))->toContain('Verify the domain');

    Queue::assertNotPushed(IssueSslForDomainJob::class);
});

it('returns error and does not dispatch for a system domain', function () {
    Queue::fake();

    $domain = SiteDomain::factory()->for($this->site)->system()->create([
        'hostname' => 'system-renew.flitops.xyz',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains/{$domain->ulid}/ssl/renew");

    $response->assertRedirect();
    expect(session('error'))->toContain('Only custom domains');

    Queue::assertNotPushed(IssueSslForDomainJob::class);
});

it('requires authentication', function () {
    $domain = SiteDomain::factory()->for($this->site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'auth-required.com',
        'verified_at' => now(),
    ]);

    $response = $this->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains/{$domain->ulid}/ssl/renew");

    $response->assertRedirect('/login');
});

it('requires team membership', function () {
    Queue::fake();

    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->forUser($otherUser)->create();
    $otherServer = Server::factory()->forTeam($otherTeam)->create();
    $otherSite = Site::factory()->create(['server_id' => $otherServer->id]);
    $domain = SiteDomain::factory()->for($otherSite)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'other-team.com',
        'verified_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$otherTeam->slug}/servers/{$otherServer->id}/sites/{$otherSite->id}/domains/{$domain->ulid}/ssl/renew");

    $response->assertForbidden();

    Queue::assertNotPushed(IssueSslForDomainJob::class);
});
