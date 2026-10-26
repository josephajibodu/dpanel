<?php

use App\Enums\TeamRoleKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\TerminalSession;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config([
        'services.terminal_gateway.url' => 'wss://flitops.test/terminal',
        'services.terminal_gateway.secret' => 'gateway-secret',
    ]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->forTeam($this->team)->active()->withPinnedHostKey('SHA256:server-key')->create();
});

it('lets only owners and admins open a terminal', function (TeamRoleKey $roleKey, bool $allowed) {
    $member = User::factory()->memberOf($this->team, $roleKey)->create();

    expect($member->can('openTerminal', $this->server))->toBe($allowed);
})->with([
    'owner' => [TeamRoleKey::Owner, true],
    'admin' => [TeamRoleKey::Admin, true],
    'developer' => [TeamRoleKey::Developer, false],
    'viewer' => [TeamRoleKey::Viewer, false],
]);

it('refuses developers on the terminal endpoints', function () {
    $developer = User::factory()->memberOf($this->team, TeamRoleKey::Developer)->create();

    $this->actingAs($developer)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('servers.terminal.show', [$this->team, $this->server]))
        ->assertForbidden();

    $this->actingAs($developer)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('servers.terminal-sessions.store', [$this->team, $this->server]))
        ->assertForbidden();

    expect(TerminalSession::count())->toBe(0);
});

it('does not let another team open a terminal on the server', function () {
    $otherTeam = Team::factory()->create();

    $this->actingAs($otherTeam->owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('servers.terminal-sessions.store', [$otherTeam, $this->server]))
        ->assertNotFound();

    expect(TerminalSession::count())->toBe(0);
});

it('asks for the password again when it was confirmed over 15 minutes ago', function () {
    $this->actingAs($this->team->owner)
        ->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp])
        ->get(route('servers.terminal.show', [$this->team, $this->server]))
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($this->team->owner)
        ->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp])
        ->postJson(route('servers.terminal-sessions.store', [$this->team, $this->server]))
        ->assertStatus(423);

    expect(TerminalSession::count())->toBe(0);
});

it('issues a single-use token and stores only its hash', function () {
    $response = $this->actingAs($this->team->owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('servers.terminal-sessions.store', [$this->team, $this->server]))
        ->assertOk()
        ->assertJsonPath('gateway_url', 'wss://flitops.test/terminal');

    $token = $response->json('token');
    $session = TerminalSession::sole();

    expect($session)
        ->server_id->toBe($this->server->id)
        ->user_id->toBe($this->team->owner->id)
        ->token_hash->toBe(hash('sha256', $token))
        ->started_at->toBeNull();
});

it('refuses to issue a token when the terminal cannot be opened', function (Closure $breakSetup, string $message) {
    $breakSetup($this->server);

    $this->actingAs($this->team->owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('servers.terminal-sessions.store', [$this->team, $this->server]))
        ->assertUnprocessable()
        ->assertJsonPath('message', $message);

    expect(TerminalSession::count())->toBe(0);
})->with([
    'gateway not configured' => [
        fn () => config(['services.terminal_gateway.url' => null]),
        'The terminal gateway is not configured on this Flitops install.',
    ],
    'server not ready' => [
        fn (Server $server) => $server->update(['status' => 'error']),
        'The server must be active and reachable before a terminal can be opened.',
    ],
    'host key not recorded' => [
        fn (Server $server) => $server->forgetHostKey(),
        "Flitops has not recorded this server's identity yet. Run a connection test first.",
    ],
]);

it('lists the sessions that were actually opened', function () {
    $opened = TerminalSession::factory()->for($this->server)->for($this->team->owner)->ended()->create();
    TerminalSession::factory()->for($this->server)->create();

    $this->actingAs($this->team->owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('servers.terminal.show', [$this->team, $this->server]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('servers/terminal/index')
            ->where('unavailableReason', null)
            ->has('sessions', 1)
            ->where('sessions.0.id', $opened->id)
            ->where('sessions.0.user_name', $this->team->owner->name)
        );
});
