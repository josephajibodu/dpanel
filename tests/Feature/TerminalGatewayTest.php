<?php

use App\Models\Server;
use App\Models\TerminalSession;

beforeEach(function () {
    config(['services.terminal_gateway.secret' => 'gateway-secret']);

    $this->server = Server::factory()->active()->withPinnedHostKey('SHA256:server-key')->create();
    $this->server->credentials()->create(['type' => 'private_key', 'value' => 'fake-private-key']);
});

function issueTerminalToken(Server $server): string
{
    return TerminalSession::issue($server, $server->team->owner, '203.0.113.5')['token'];
}

it('rejects requests without the gateway secret', function (?string $secret) {
    $token = issueTerminalToken($this->server);

    $this->withToken((string) $secret)
        ->postJson(route('terminal-gateway.redeem'), ['token' => $token])
        ->assertUnauthorized();

    expect(TerminalSession::sole()->started_at)->toBeNull();
})->with([
    'missing' => [null],
    'wrong' => ['not-the-secret'],
]);

it('hides the endpoints when no gateway secret is configured', function () {
    config(['services.terminal_gateway.secret' => null]);

    $this->withToken('')
        ->postJson(route('terminal-gateway.redeem'), ['token' => 'anything'])
        ->assertNotFound();
});

it('exchanges a token for the connection details and marks the session started', function () {
    $token = issueTerminalToken($this->server);

    $this->withToken('gateway-secret')
        ->postJson(route('terminal-gateway.redeem'), ['token' => $token])
        ->assertOk()
        ->assertExactJson([
            'session_id' => TerminalSession::sole()->id,
            'host' => $this->server->ip_address,
            'port' => $this->server->ssh_port,
            'user' => config('server.user'),
            'private_key' => 'fake-private-key',
            'host_key_type' => 'ssh-ed25519',
            'host_key_fingerprint' => 'SHA256:server-key',
        ]);

    expect(TerminalSession::sole()->started_at)->not->toBeNull();
});

it('accepts a token only once', function () {
    $token = issueTerminalToken($this->server);

    $this->withToken('gateway-secret')
        ->postJson(route('terminal-gateway.redeem'), ['token' => $token])
        ->assertOk();

    $this->withToken('gateway-secret')
        ->postJson(route('terminal-gateway.redeem'), ['token' => $token])
        ->assertForbidden()
        ->assertJsonMissingPath('private_key');
});

it('refuses an expired token', function () {
    $token = issueTerminalToken($this->server);

    $this->travel(TerminalSession::TOKEN_TTL_SECONDS + 1)->seconds();

    $this->withToken('gateway-secret')
        ->postJson(route('terminal-gateway.redeem'), ['token' => $token])
        ->assertForbidden()
        ->assertJsonMissingPath('private_key');

    expect(TerminalSession::sole()->started_at)->toBeNull();
});

it('refuses to connect when the server identity was reset after the token was issued', function () {
    $token = issueTerminalToken($this->server);
    $this->server->forgetHostKey();

    $this->withToken('gateway-secret')
        ->postJson(route('terminal-gateway.redeem'), ['token' => $token])
        ->assertConflict()
        ->assertJsonMissingPath('private_key');

    expect(TerminalSession::sole()->ended_at)->not->toBeNull();
});

it('records when a session ends', function () {
    $session = TerminalSession::factory()->for($this->server)->started()->create();

    $this->withToken('gateway-secret')
        ->postJson(route('terminal-gateway.end', $session))
        ->assertNoContent();

    expect($session->fresh()->ended_at)->not->toBeNull();
});
