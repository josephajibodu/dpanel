<?php

use App\Enums\SiteDomainType;
use App\Http\Resources\SiteDomainResource;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

function sslStatusFor(SiteDomain $domain): string
{
    $request = request()->create('/');
    $request->setRouteResolver(fn () => null);

    return (new SiteDomainResource($domain))->toArray($request)['ssl_status'];
}

it('reports none when there is no expiry', function () {
    $domain = SiteDomain::factory()->for($this->site)->create([
        'type' => SiteDomainType::Custom,
        'ssl_expires_at' => null,
    ]);

    expect(sslStatusFor($domain))->toBe('none');
});

it('reports valid when expiry is more than 30 days away', function () {
    $domain = SiteDomain::factory()->for($this->site)->create([
        'type' => SiteDomainType::Custom,
        'ssl_expires_at' => now()->addDays(45),
    ]);

    expect(sslStatusFor($domain))->toBe('valid');
});

it('reports expiring_soon when expiry is within 30 days', function () {
    $domain = SiteDomain::factory()->for($this->site)->create([
        'type' => SiteDomainType::Custom,
        'ssl_expires_at' => now()->addDays(10),
    ]);

    expect(sslStatusFor($domain))->toBe('expiring_soon');
});

it('reports expired when expiry is in the past', function () {
    $domain = SiteDomain::factory()->for($this->site)->create([
        'type' => SiteDomainType::Custom,
        'ssl_expires_at' => now()->subDay(),
    ]);

    expect(sslStatusFor($domain))->toBe('expired');
});
