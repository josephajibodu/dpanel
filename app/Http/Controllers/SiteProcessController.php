<?php

namespace App\Http\Controllers;

use App\Http\Resources\CronJobResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Http\Resources\WorkerResource;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

class SiteProcessController extends Controller
{
    public function index(Team $team, Server $server, Site $site): Response
    {
        $this->authorize('view', $site);

        abort_unless($site->server_id === $server->id, 404);

        $workers = $server->workers()->where('site_id', $site->id)->get();
        $cronJobs = $server->cronJobs()->where('site_id', $site->id)->get();

        $workers->each->setRelation('site', $site);
        $cronJobs->each->setRelation('site', $site);

        return Inertia::render('sites/processes/index', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
            'serverIsReady' => $server->isReady(),
            'workers' => WorkerResource::collection($workers),
            'cronJobs' => CronJobResource::collection($cronJobs),
        ]);
    }
}
