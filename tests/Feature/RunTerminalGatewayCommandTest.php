<?php

it('skips the gateway when the terminal is not configured', function () {
    config(['services.terminal_gateway.secret' => null]);

    $this->artisan('flitops:terminal-gateway')
        ->expectsOutputToContain('The terminal is not configured')
        ->assertSuccessful();
});
