<?php

namespace App\Http\Controllers;

use App\Actions\Servers\TransferServerAction;
use App\Http\Requests\TransferServerRequest;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class ServerTransferController extends Controller
{
    /**
     * Move the server and everything on it to another of the user's teams.
     */
    public function store(TransferServerRequest $request, Team $team, Server $server, TransferServerAction $action): RedirectResponse
    {
        $destination = $request->destination();

        $action->execute($server, $destination, $request->user(), $request->providerAccount());

        return redirect()
            ->route('servers.show', [$destination, $server])
            ->with('success', "{$server->name} now belongs to {$destination->name}.");
    }
}
