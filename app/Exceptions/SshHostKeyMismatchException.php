<?php

namespace App\Exceptions;

use App\Models\Server;

/**
 * The server answered with a different host key from the one recorded the
 * first time we connected. Either the server was rebuilt, or something on the
 * network is impersonating it, so the connection is dropped before any
 * credentials or commands are sent. Never retry this as a network blip.
 */
class SshHostKeyMismatchException extends SshConnectionException
{
    public function __construct(
        Server $server,
        public readonly string $presentedFingerprint,
    ) {
        parent::__construct(
            "This server's identity has changed since we first connected to it, so we stopped before sending anything to it. "
            .'This is expected if the server was rebuilt or reinstalled, but it can also mean someone is intercepting the connection. '
            ."If you rebuilt this server, reset its stored identity by running `php artisan flitops:server:forget-host-key {$server->id}`, then try again. "
            .'If you did not, contact your hosting provider before reconnecting.',
            $server->ip_address,
            $server->ssh_port,
        );
    }
}
