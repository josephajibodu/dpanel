<?php

namespace App\Http\Controllers;

use App\Actions\Teams\ChangeTeamMemberRole;
use App\Actions\Teams\InviteTeamMember;
use App\Actions\Teams\RemoveTeamMember;
use App\Http\Requests\InviteTeamMemberRequest;
use App\Http\Requests\UpdateTeamMemberRoleRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class TeamMemberController extends Controller
{
    public function store(InviteTeamMemberRequest $request, Team $team, InviteTeamMember $action): RedirectResponse
    {
        $action->execute($team, $request->validated('email'), $request->teamRole());

        return redirect()
            ->back()
            ->with('success', 'Invitation sent.');
    }

    public function update(UpdateTeamMemberRoleRequest $request, Team $team, User $user, ChangeTeamMemberRole $action): RedirectResponse
    {
        $action->execute($team, $user, $request->teamRole());

        return redirect()
            ->back()
            ->with('success', 'Role updated.');
    }

    public function destroy(Team $team, User $user, RemoveTeamMember $action): RedirectResponse
    {
        $this->authorize('removeMember', [$team, $user]);

        $action->execute($team, $user);

        return redirect()
            ->back()
            ->with('success', 'Team member removed.');
    }

    /**
     * Take the signed-in user out of the team, the same way an admin would
     * remove them, and send them to their personal team.
     */
    public function leave(Team $team, RemoveTeamMember $action): RedirectResponse
    {
        $this->authorize('leave', $team);

        $user = auth()->user();

        $action->execute($team, $user);

        $user->refresh();

        $currentTeam = $user->currentTeam;

        $redirect = $currentTeam && $user->belongsToTeam($currentTeam)
            ? redirect()->route('dashboard', $currentTeam)
            : redirect()->route('home');

        return $redirect->with('success', "You left {$team->name}.");
    }
}
