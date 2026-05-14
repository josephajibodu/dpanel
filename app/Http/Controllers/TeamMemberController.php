<?php

namespace App\Http\Controllers;

use App\Actions\Teams\InviteTeamMember;
use App\Actions\Teams\RemoveTeamMember;
use App\Http\Requests\InviteTeamMemberRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class TeamMemberController extends Controller
{
    public function store(InviteTeamMemberRequest $request, Team $team, InviteTeamMember $action): RedirectResponse
    {
        $action->execute($team, $request->validated('email'), $request->validated('role'));

        return redirect()
            ->back()
            ->with('success', 'Invitation sent.');
    }

    public function destroy(Team $team, User $user, RemoveTeamMember $action): RedirectResponse
    {
        $this->authorize('removeMember', $team);

        if ($team->userIsOwner($user)) {
            return redirect()
                ->back()
                ->with('error', 'You cannot remove the team owner.');
        }

        $action->execute($team, $user);

        return redirect()
            ->back()
            ->with('success', 'Team member removed.');
    }
}
