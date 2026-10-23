<?php

use App\Enums\TeamRoleKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->forTeam($this->team)->withPinnedHostKey('SHA256:old-key')->create();
});

it('lets owners and admins reset the server identity', function (TeamRoleKey $roleKey, bool $allowed) {
    $member = User::factory()->memberOf($this->team, $roleKey)->create();

    $response = $this->actingAs($member)
        ->delete(route('servers.host-key.destroy', [$this->team, $this->server]));

    if ($allowed) {
        $response->assertRedirect()->assertSessionHas('success');
        expect($this->server->fresh())
            ->host_key_type->toBeNull()
            ->host_key_fingerprint->toBeNull();
    } else {
        $response->assertForbidden();
        expect($this->server->fresh()->host_key_fingerprint)->toBe('SHA256:old-key');
    }
})->with([
    'owner' => [TeamRoleKey::Owner, true],
    'admin' => [TeamRoleKey::Admin, true],
    'developer' => [TeamRoleKey::Developer, false],
    'viewer' => [TeamRoleKey::Viewer, false],
]);

it('does not let another team reset the server identity', function () {
    $otherTeam = Team::factory()->create();

    $this->actingAs($otherTeam->owner)
        ->delete(route('servers.host-key.destroy', [$otherTeam, $this->server]))
        ->assertNotFound();

    $this->actingAs($otherTeam->owner)
        ->delete(route('servers.host-key.destroy', [$this->team, $this->server]))
        ->assertForbidden();

    expect($this->server->fresh()->host_key_fingerprint)->toBe('SHA256:old-key');
});

it('shows the recorded identity on the settings page', function () {
    $this->actingAs($this->team->owner)
        ->get(route('servers.settings', [$this->team, $this->server]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('host_key', ['type' => 'ssh-ed25519', 'fingerprint' => 'SHA256:old-key'])
        );

    $this->server->forgetHostKey();

    $this->actingAs($this->team->owner)
        ->get(route('servers.settings', [$this->team, $this->server]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('host_key', null));
});
