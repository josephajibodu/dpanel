<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;

class DeleteTeam
{
    public function execute(User $user, Team $team): void
    {
        // Move the deleting owner to their personal team if they have one
        $personalTeam = $user->ownedTeams()
            ->where('personal_team', true)
            ->where('id', '!=', $team->id)
            ->first();

        if ($personalTeam) {
            $user->switchTeam($personalTeam);
        }

        $team->delete();
    }
}
