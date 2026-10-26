<?php

namespace App\Http\Controllers;

use App\Models\TerminalSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Internal endpoints the terminal gateway calls. They sit behind the shared
 * gateway secret, never a user session.
 */
class TerminalGatewayController extends Controller
{
    /**
     * Exchange a single-use browser token for what the gateway needs to open
     * the SSH connection. The private key only ever travels from here to the
     * gateway, never to the browser.
     */
    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $session = TerminalSession::redeem($validated['token']);

        if ($session === null) {
            return response()->json(['message' => 'Invalid or expired terminal token.'], 403);
        }

        $server = $session->server;
        $privateKey = $server->credential('private_key')?->value;

        if (! $server->hasPinnedHostKey() || $privateKey === null) {
            $session->update(['ended_at' => now()]);

            return response()->json(['message' => 'This server cannot be reached from the terminal yet.'], 409);
        }

        Log::info('Terminal session started', [
            'terminal_session_id' => $session->id,
            'user_id' => $session->user_id,
            'server_id' => $server->id,
        ]);

        return response()->json([
            'session_id' => $session->id,
            'host' => $server->ip_address,
            'port' => $server->ssh_port,
            'user' => config('server.user'),
            'private_key' => $privateKey,
            'host_key_type' => $server->host_key_type,
            'host_key_fingerprint' => $server->host_key_fingerprint,
        ]);
    }

    /**
     * Record that a session closed, whether the user left, went idle or hit
     * the time limit.
     */
    public function end(TerminalSession $terminalSession): Response
    {
        if ($terminalSession->ended_at === null) {
            $terminalSession->update(['ended_at' => now()]);

            Log::info('Terminal session ended', [
                'terminal_session_id' => $terminalSession->id,
                'user_id' => $terminalSession->user_id,
                'server_id' => $terminalSession->server_id,
            ]);
        }

        return response()->noContent();
    }
}
