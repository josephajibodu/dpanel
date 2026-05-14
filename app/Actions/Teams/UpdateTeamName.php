<?php

namespace App\Actions\Teams;

use App\Models\Team;

class UpdateTeamName
{
    public function execute(Team $team, string $name): void
    {
        $team->update(['name' => $name]);
    }
}
