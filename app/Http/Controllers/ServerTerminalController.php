<?php

namespace App\Http\Controllers;

use App\Http\Resources\ServerResource;
use App\Models\Server;
use App\Models\Team;
use App\Models\TerminalSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServerTerminalController extends Controller
{
    /**
     * How many past sessions the page lists.
     */
    private const RECENT_SESSIONS = 20;

    public function show(Team $team, Server $server): Response
    {
        $this->authorize('openTerminal', $server);

        $sessions = $server->terminalSessions()
            ->whereNotNull('started_at')
            ->with('user:id,name')
            ->latest('started_at')
            ->limit(self::RECENT_SESSIONS)
            ->get()
            ->map(fn (TerminalSession $session): array => [
                'id' => $session->id,
                'user_name' => $session->user?->name,
                'ip_address' => $session->ip_address,
                'started_at' => $session->started_at?->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
            ]);

        return Inertia::render('servers/terminal/index', [
            'server' => new ServerResource($server),
            'unavailableReason' => $this->unavailableReason($server),
            'sessions' => $sessions,
        ]);
    }

    /**
     * Issue a single-use token the browser hands to the terminal gateway.
     */
    public function store(Request $request, Team $team, Server $server): JsonResponse
    {
        $this->authorize('openTerminal', $server);

        $reason = $this->unavailableReason($server);

        if ($reason !== null) {
            return response()->json(['message' => $reason], 422);
        }

        ['token' => $token] = TerminalSession::issue($server, $request->user(), $request->ip());

        return response()->json([
            'gateway_url' => config('services.terminal_gateway.url'),
            'token' => $token,
        ]);
    }

    /**
     * Why a terminal cannot be opened on this server right now, if anything.
     */
    private function unavailableReason(Server $server): ?string
    {
        if (blank(config('services.terminal_gateway.url')) || blank(config('services.terminal_gateway.secret'))) {
            return 'The terminal gateway is not configured on this Flitops install.';
        }

        if (! $server->isReady()) {
            return 'The server must be active and reachable before a terminal can be opened.';
        }

        if (! $server->hasPinnedHostKey()) {
            return "Flitops has not recorded this server's identity yet. Run a connection test first.";
        }

        return null;
    }
}
