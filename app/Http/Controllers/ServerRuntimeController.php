<?php

namespace App\Http\Controllers;

use App\Enums\NodeVersion;
use App\Enums\ServiceStatus;
use App\Http\Requests\UpdateNodeVersionRequest;
use App\Http\Resources\ServerResource;
use App\Jobs\InstallNodeVersionJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServerRuntimeController extends Controller
{
    public function index(Team $team, Server $server): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('servers/runtime/index', [
            'server' => new ServerResource($server),
            'serverIsReady' => $server->isReady(),
            'node' => [
                'version' => $server->node_version->value,
                'label' => $server->node_version->label(),
                'is_end_of_life' => $server->node_version->isEndOfLife(),
                'status' => $server->node_status->value,
            ],
            'availableNodeVersions' => collect(NodeVersion::cases())
                ->map(fn (NodeVersion $version): array => [
                    'value' => $version->value,
                    'label' => $version->label(),
                ])
                ->all(),
        ]);
    }

    public function updateNodeVersion(UpdateNodeVersionRequest $request, Team $team, Server $server): RedirectResponse
    {
        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to change the Node.js version.');
        }

        if ($server->node_status === ServiceStatus::Installing) {
            return redirect()
                ->back()
                ->with('error', 'A Node.js version change is already in progress.');
        }

        $version = NodeVersion::from($request->validated('version'));

        if ($server->node_version === $version && $server->node_status === ServiceStatus::Active) {
            return redirect()
                ->back()
                ->with('error', "Node.js {$version->value} is already installed.");
        }

        $server->update(['node_status' => ServiceStatus::Pending]);
        InstallNodeVersionJob::dispatch($server, $version);

        return redirect()
            ->back()
            ->with('success', "Switching to Node.js {$version->value}.");
    }
}
