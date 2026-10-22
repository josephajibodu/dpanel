<?php

namespace App\Http\Controllers;

use App\Actions\Sites\IsolateSiteAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class SiteIsolationController extends Controller
{
    /**
     * Start moving an existing site to its own Linux user.
     */
    public function store(Team $team, Server $server, Site $site, IsolateSiteAction $action): RedirectResponse
    {
        $this->authorize('isolate', $site);

        abort_unless($site->server_id === $server->id, 404);

        try {
            $action->trigger($site);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['isolate' => $e->getMessage()]);
        }

        return back()->with('success', 'Moving the site to its own user. It may be unavailable for a few seconds.');
    }
}
