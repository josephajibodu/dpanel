<?php

namespace App\Http\Controllers;

use App\Actions\Sites\RunSiteCommand;
use App\Http\Requests\StoreSiteCommandRunRequest;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteCommandRunResource;
use App\Http\Resources\SiteResource;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCommandRun;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SiteCommandRunController extends Controller
{
    public function index(Team $team, Server $server, Site $site): Response
    {
        $this->authorize('view', $site);

        $commandRuns = $site->commandRuns()
            ->with('user')
            ->latest()
            ->paginate(20);

        return Inertia::render('sites/commands/index', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site->load('server')),
            'commandRuns' => SiteCommandRunResource::collection($commandRuns),
        ]);
    }

    public function store(
        StoreSiteCommandRunRequest $request,
        Team $team,
        Server $server,
        Site $site,
        RunSiteCommand $runSiteCommand,
    ): RedirectResponse {
        $runSiteCommand->run($site, $request->validated('command'));

        return redirect()
            ->route('servers.sites.command-runs.index', [$team, $server, $site])
            ->with('success', 'Command has been queued to run.');
    }

    public function show(Team $team, Server $server, Site $site, SiteCommandRun $commandRun): JsonResponse
    {
        $this->authorize('view', $site);

        if ($commandRun->site_id !== $site->id) {
            abort(404);
        }

        $commandRun->load('user');

        return response()->json(
            SiteCommandRunResource::make($commandRun)->withOutput()->resolve()
        );
    }
}
