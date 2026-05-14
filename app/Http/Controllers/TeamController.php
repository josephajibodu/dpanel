<?php

namespace App\Http\Controllers;

use App\Actions\Teams\CreateTeam;
use App\Actions\Teams\DeleteTeam;
use App\Actions\Teams\UpdateTeamName;
use App\Http\Requests\StoreTeamRequest;
use App\Http\Requests\UpdateTeamNameRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function show(Team $team): Response
    {
        $this->authorize('view', $team);

        $team->load(['owner', 'users', 'invitations']);

        return Inertia::render('settings/team', [
            'team' => $team,
            'members' => $team->users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->pivot->role,
            ]),
            'invitations' => $team->invitations,
        ]);
    }

    public function store(StoreTeamRequest $request, CreateTeam $action): RedirectResponse
    {
        $team = $action->execute($request->user(), $request->validated('name'));

        return redirect()
            ->route('teams.show', $team)
            ->with('success', 'Team created.');
    }

    public function update(UpdateTeamNameRequest $request, Team $team, UpdateTeamName $action): RedirectResponse
    {
        $action->execute($team, $request->validated('name'));

        return redirect()
            ->back()
            ->with('success', 'Team name updated.');
    }

    public function destroy(Team $team, DeleteTeam $action): RedirectResponse
    {
        $this->authorize('delete', $team);

        $action->execute(auth()->user(), $team);

        return redirect()
            ->route('servers.index')
            ->with('success', 'Team deleted.');
    }
}
