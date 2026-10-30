<?php

namespace App\Http\Controllers;

use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Services\WebServer\SiteErrorLogReader;
use Inertia\Inertia;
use Inertia\Response;

class SiteLogController extends Controller
{
    public function index(Team $team, Server $server, Site $site, SiteErrorLogReader $errorLogReader): Response
    {
        $this->authorize('view', $site);

        abort_unless($site->server_id === $server->id, 404);

        $site->setRelation('server', $server);

        return Inertia::render('sites/logs/index', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
            'webServer' => $server->webServer()->label(),
            'errorLogAvailable' => $errorLogReader->isAvailable($site),
            'errorLog' => Inertia::defer(function () use ($site, $errorLogReader): array {
                try {
                    return ['lines' => $errorLogReader->recentLines($site, 100), 'failed' => false];
                } catch (\Throwable) {
                    return ['lines' => [], 'failed' => true];
                }
            }),
        ]);
    }
}
