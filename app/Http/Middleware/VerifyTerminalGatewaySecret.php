<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only the terminal gateway reach its internal endpoints: it must send
 * the shared secret as a bearer token. When no secret is configured the
 * endpoints do not exist, so the terminal is off rather than open.
 */
class VerifyTerminalGatewaySecret
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.terminal_gateway.secret');

        if (! is_string($secret) || $secret === '') {
            abort(404);
        }

        if (! hash_equals($secret, (string) $request->bearerToken())) {
            abort(401);
        }

        return $next($request);
    }
}
