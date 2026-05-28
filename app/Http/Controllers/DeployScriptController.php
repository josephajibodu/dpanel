<?php

namespace App\Http\Controllers;

use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeployScriptController extends Controller
{
    public function show(Team $team, Server $server, Site $site): Response
    {
        $this->authorize('view', $site);

        $site->load(['server', 'deployScript']);

        return Inertia::render('sites/deploy-script/show', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
        ]);
    }

    public function update(Request $request, Team $team, Server $server, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'script' => ['required', 'string', 'max:65535'],
        ], [
            'script.required' => 'Deploy script cannot be empty.',
            'script.max' => 'Deploy script is too long.',
        ]);

        $site->deployScript()->updateOrCreate(
            ['site_id' => $site->id],
            ['script' => $validated['script']]
        );

        return redirect()
            ->back()
            ->with('success', 'Deploy script updated successfully.');
    }
}
