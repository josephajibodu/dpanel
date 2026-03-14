<?php

use App\Models\Server;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = Server::factory()->create([
        'user_id' => $this->user->id,
        'ip_address' => '192.168.1.1',
        'ssh_port' => 22,
    ]);
});

it('shows server settings page', function () {
    $response = $this->actingAs($this->user)
        ->get("/servers/{$this->server->id}/settings");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('servers/settings')
            ->has('server')
            ->has('ssh_command')
            ->has('has_ssh_key')
            ->where('ssh_command', 'ssh artisan@192.168.1.1')
            ->where('has_ssh_key', false)
        );
});

it('includes port in ssh command when not 22', function () {
    $this->server->update(['ssh_port' => 2222]);

    $response = $this->actingAs($this->user)
        ->get("/servers/{$this->server->id}/settings");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ssh_command', 'ssh artisan@192.168.1.1 -p 2222')
        );
});

it('returns null ssh command when server has no ip', function () {
    $this->server->update(['ip_address' => null]);

    $response = $this->actingAs($this->user)
        ->get("/servers/{$this->server->id}/settings");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ssh_command', null)
        );
});

it('shows has_ssh_key true when user has key synced to server', function () {
    $sshKey = SshKey::factory()->create(['user_id' => $this->user->id]);
    DB::table('server_ssh_key')->insert([
        'server_id' => $this->server->id,
        'ssh_key_id' => $sshKey->id,
        'status' => 'synced',
        'created_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->get("/servers/{$this->server->id}/settings");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('has_ssh_key', true)
        );
});

it('denies guest access to server settings', function () {
    $response = $this->get("/servers/{$this->server->id}/settings");

    $response->assertRedirect();
});

it('denies access to server settings for another users server', function () {
    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)
        ->get("/servers/{$this->server->id}/settings");

    $response->assertForbidden();
});
