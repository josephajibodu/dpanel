<?php

namespace App\Http\Controllers;

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
        ])->values()->all();

        return Inertia::render('servers/processes/index', [
            'server' => new ServerResource($server),
            'serverIsReady' => $server->isReady(),
            'workers' => WorkerResource::collection($server->workers),
            'cronJobs' => CronJobResource::collection($server->cronJobs),
            'sites' => $sites,
            'defaultUser' => config('server.user'),
        ]);
    }
}
