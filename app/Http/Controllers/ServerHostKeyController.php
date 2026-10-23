<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class ServerHostKeyController extends Controller
{
    /**
     * Forget the server's recorded identity after it was rebuilt or
     * reinstalled. The next successful connection records the new one.
     */
    public function destroy(Team $team, Server $server): RedirectResponse
    {
        $this->authorize('resetHostKey', $server);

        $server->forgetHostKey();

        return back()->with('success', 'Server identity reset. The next connection will record the new one.');
    }
}
