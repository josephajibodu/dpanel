<?php

use App\Enums\SiteDomainType;
use App\Enums\WwwRedirect;
use App\Jobs\SyncSiteNginxJob;
use App\Jobs\VerifyCustomDomainJob;
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

it('stores a custom domain with a verification token and dispatches nginx sync', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains", [
            'hostname' => 'my-custom.com',
            'wildcard_enabled' => false,
            'www_redirect' => WwwRedirect::FromWww->value,
        ]);

    $response->assertRedirect();

    $domain = \App\Models\SiteDomain::query()->where('hostname', 'my-custom.com')->first();

    expect($domain)->not->toBeNull()
        ->and($domain->site_id)->toBe($this->site->id)
        ->and($domain->type->value)->toBe(SiteDomainType::Custom->value)
        ->and($domain->verified_at)->toBeNull()
        ->and($domain->ssl_enabled_at)->toBeNull();

    Queue::assertPushed(SyncSiteNginxJob::class);
});

it('dispatches verify job for an unverified custom domain', function () {
    Queue::fake();

    $domain = SiteDomain::factory()->for($this->site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'to-verify.com',
        'verified_at' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains/{$domain->ulid}/verify");

    $response->assertRedirect();

    Queue::assertPushed(VerifyCustomDomainJob::class, fn ($job) => $job->siteDomain->id === $domain->id);
});

it('returns error when attempting to verify a system domain', function () {
    Queue::fake();

    $domain = SiteDomain::factory()->for($this->site)->system()->create([
        'hostname' => 'unique-system.flitops.xyz',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains/{$domain->ulid}/verify");

    $response->assertRedirect();
    expect(session('error'))->toContain('Only custom domains');

    Queue::assertNotPushed(VerifyCustomDomainJob::class);
});

it('returns success immediately when domain is already verified', function () {
    Queue::fake();

    $domain = SiteDomain::factory()->for($this->site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'already-verified.com',
        'verified_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/domains/{$domain->ulid}/verify");

    $response->assertRedirect();
    expect(session('success'))->toContain('already verified');

    Queue::assertNotPushed(VerifyCustomDomainJob::class);
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
