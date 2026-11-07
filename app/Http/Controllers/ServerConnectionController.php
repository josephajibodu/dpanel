<?php

namespace App\Http\Controllers;

use App\Actions\ServerConnections\AllowServerConnection;
use App\Actions\ServerConnections\RevokeServerConnection;
use App\Enums\ServerConnectionStatus;
use App\Enums\ServerStatus;
use App\Events\ServerConnectionsUpdated;
use App\Http\Requests\StoreServerConnectionRequest;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use App\Models\ServerConnection;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ServerConnectionController extends Controller
{
    public function index(Team $team, Server $server): Response
    {
        $this->authorize('view', $server);
        abort_unless($server->acceptsConnections(), 404);

        $connections = $server->hostedConnections()->with('client')->oldest()->get();

        $availableServers = $team->servers()
            ->whereKeyNot($server->id)
            ->whereNotIn('id', $connections->pluck('client_server_id'))
            ->where('status', ServerStatus::Active)
            ->orderBy('name')
            ->get()
            ->map(fn (Server $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'private_network' => ServerConnection::shareAPrivateNetwork($server, $client),
            ])
            ->values()
            ->all();

        $connectionsUpdated = new ServerConnectionsUpdated($server);

        return Inertia::render('servers/connections/index', [
            'server' => new ServerResource($server),
            'serverIsReady' => $server->isReady(),
            'services' => collect($server->sharedServicePorts())
                ->map(fn (int $port, string $label): array => ['label' => $label, 'port' => $port])
                ->values()
                ->all(),
            'connections' => Inertia::live($connections->map(fn (ServerConnection $connection): array => [
                'id' => $connection->id,
                'client_name' => $connection->client->name,
                'host_address' => $connection->host_address,
                'client_address' => $connection->client_address,
                'private_network' => $connection->usesPrivateNetwork(),
                'status' => $connection->status->value,
                'status_label' => $connection->status->label(),
                'error_message' => $connection->error_message,
            ])->all(), on: $connectionsUpdated),
            'availableServers' => Inertia::live($availableServers, on: $connectionsUpdated),
            'redisPassword' => Inertia::live(
                $server->sharesRedis() && auth()->user()->can('viewSecrets', $server)
                    ? $server->credential('redis_password')?->value
                    : null,
                on: $connectionsUpdated,
            ),
        ]);
    }

    public function store(StoreServerConnectionRequest $request, Team $team, Server $server): RedirectResponse
    {
        abort_unless($server->acceptsConnections(), 404);

        $client = $team->servers()->findOrFail($request->validated('client_server_id'));

        try {
            app(AllowServerConnection::class)->allow($server, $client);
        } catch (RuntimeException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Opening {$server->name} to {$client->name}.");
    }

    public function destroy(Team $team, Server $server, ServerConnection $hostedConnection): RedirectResponse
    {
        $this->authorize('update', $server);

        if ($hostedConnection->status === ServerConnectionStatus::Removing) {
            return redirect()->back()->with('error', 'This connection is already being removed.');
        }

        app(RevokeServerConnection::class)->revoke($hostedConnection);

        return redirect()->back()->with('success', 'The connection is being removed.');
    }
}
