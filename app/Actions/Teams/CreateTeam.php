<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;

class CreateTeam
{
    public function execute(User $user, string $name, bool $personalTeam = false): Team
    {
        $team = Team::create([
            'user_id' => $user->id,
            'name' => $name,
            'personal_team' => $personalTeam,
        ]);

        $team->users()->attach($user, ['role' => 'owner']);

        return $team;
    }
}
