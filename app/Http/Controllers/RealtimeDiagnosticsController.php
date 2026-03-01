<?php

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Events\RealtimeDiagnosticMessage;
use App\Events\ServerStatusChanged;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RealtimeDiagnosticsController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        return Inertia::render('realtime/test', [
            'channel' => "realtime.diagnostics.{$user->id}",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'reverb' => [
                'host' => config('reverb.apps.apps.0.options.host'),
                'port' => config('reverb.apps.apps.0.options.port'),
                'scheme' => config('reverb.apps.apps.0.options.scheme'),
            ],
        ]);
    }

    public function trigger(Request $request): Response
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        event(new RealtimeDiagnosticMessage(
            userId: $user->id,
            eventId: (string) Str::ulid(),
            message: $validated['message'] ?? 'Manual realtime diagnostics ping',
            sentAtIso: now()->toIso8601String(),
        ));

        return response()->noContent();
    }
}
