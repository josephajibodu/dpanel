<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;

class RemoveTeamMember
{
    public function execute(Team $team, User $member): void
    {
        $team->users()->detach($member);

        // If this was their current team, switch them to their personal team
        if ($member->current_team_id === $team->id) {
            $personalTeam = $member->ownedTeams()->where('personal_team', true)->first();

            if ($personalTeam) {
                $member->switchTeam($personalTeam);
            }
        }
    }
}
