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
}
