<?php

namespace App\Http\Controllers;

use App\Actions\Database\CreateServerDatabase;
use App\Actions\Database\DestroyServerDatabase;
use App\Http\Requests\StoreServerDatabaseRequest;
use App\Http\Resources\DatabaseUserResource;
use App\Http\Resources\ServerDatabaseResource;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use App\Models\ServerDatabase;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServerDatabaseController extends Controller
{
    public function index(Server $server): Response
    {
        $this->authorize('view', $server);

        $server->load(['databases', 'databaseUsers']);

        return Inertia::render('servers/databases/index', [
            'server' => new ServerResource($server),
            'serverIsReady' => $server->isReady(),
            'databases' => ServerDatabaseResource::collection($server->databases),
            'databaseUsers' => DatabaseUserResource::collection($server->databaseUsers),
        ]);
    }

    public function store(StoreServerDatabaseRequest $request, Server $server): RedirectResponse
    {
        $this->authorize('view', $server);

        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to create databases.');
        }

        app(CreateServerDatabase::class)->create($server, $request->validated());

        return redirect()
            ->back()
            ->with('success', 'Database is being created.');
    }

    public function destroy(Server $server, ServerDatabase $server_database): RedirectResponse
    {
        $this->authorize('view', $server);

        app(DestroyServerDatabase::class)->delete($server_database);

        return redirect()
            ->back()
            ->with('success', 'Database is being removed.');
    }
}
