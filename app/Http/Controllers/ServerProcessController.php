<?php

namespace App\Http\Controllers;

use App\Events\ServerProcessesUpdated;
use App\Http\Resources\CronJobResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\WorkerResource;
use App\Models\Server;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

class ServerProcessController extends Controller
{
    public function index(Team $team, Server $server): Response
    {
        $this->authorize('view', $server);

        $server->load([
            'workers' => fn ($q) => $q->with('site'),
            'cronJobs' => fn ($q) => $q->with('site'),
            'sites',
        ]);

        $sites = $server->sites->map(fn ($site) => [
            'id' => $site->id,
            'domain' => $site->domain,
            'system_user' => $site->systemUser(),
        ])->values()->all();

        $processesUpdated = new ServerProcessesUpdated($server->id);

        return Inertia::render('servers/processes/index', [
            'server' => new ServerResource($server),
            'serverIsReady' => $server->isReady(),
            'workers' => Inertia::live(WorkerResource::collection($server->workers), on: $processesUpdated),
            'cronJobs' => Inertia::live(CronJobResource::collection($server->cronJobs), on: $processesUpdated),
            'sites' => $sites,
            'defaultUser' => config('server.user'),
        ]);
    }
}
