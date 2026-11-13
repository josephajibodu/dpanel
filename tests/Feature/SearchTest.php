<?php

use App\Enums\Provider;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
});

it('lists the team servers, sites and databases for search', function () {
    $server = Server::factory()->forTeam($this->team)->create([
        'name' => 'web-1',
        'ip_address' => '203.0.113.10',
        'provider' => Provider::Hetzner,
        'php_version' => '8.3',
        'web_server' => 'nginx',
        'database_type' => 'mysql',
    ]);
    $site = Site::factory()->forServer($server)->create([
        'domain' => 'shop.example.com',
        'aliases' => ['www.shop.example.com'],
        'repository' => 'acme/shop',
    ]);
    $database = ServerDatabase::factory()->create([
        'server_id' => $server->id,
        'name' => 'shop_production',
    ]);

    $this->actingAs($this->user)
        ->getJson("/{$this->team->slug}/search")
        ->assertOk()
        ->assertExactJson([
            'servers' => [[
                'id' => $server->id,
                'name' => 'web-1',
                'ip_address' => '203.0.113.10',
                'provider' => 'Hetzner',
                'has_php' => true,
                'supports_sites' => true,
                'database_type' => 'mysql',
                'accepts_connections' => true,
            ]],
            'sites' => [[
                'id' => $site->id,
                'server_id' => $server->id,
                'domain' => 'shop.example.com',
                'aliases' => ['www.shop.example.com'],
                'repository' => 'acme/shop',
            ]],
            'databases' => [[
                'id' => $database->id,
                'server_id' => $server->id,
                'name' => 'shop_production',
            ]],
        ]);
});

it('leaves out servers, sites and databases of other teams', function () {
    $ownServer = Server::factory()->forTeam($this->team)->create(['name' => 'own-server']);
    Site::factory()->forServer($ownServer)->create(['domain' => 'own.example.com']);
    ServerDatabase::factory()->create(['server_id' => $ownServer->id, 'name' => 'own_db']);

    $otherServer = Server::factory()->forTeam(Team::factory()->create())->create(['name' => 'other-server']);
    Site::factory()->forServer($otherServer)->create(['domain' => 'other.example.com']);
    ServerDatabase::factory()->create(['server_id' => $otherServer->id, 'name' => 'other_db']);

    $response = $this->actingAs($this->user)
        ->getJson("/{$this->team->slug}/search")
        ->assertOk();

    expect($response->json('servers.*.name'))->toBe(['own-server'])
        ->and($response->json('sites.*.domain'))->toBe(['own.example.com'])
        ->and($response->json('databases.*.name'))->toBe(['own_db']);
});

it('refuses people who are not in the team', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->getJson("/{$this->team->slug}/search")
        ->assertForbidden();
});

it('requires sign in', function () {
    $this->getJson("/{$this->team->slug}/search")
        ->assertUnauthorized();
});
