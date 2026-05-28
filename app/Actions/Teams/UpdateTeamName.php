<?php

namespace App\Actions\Teams;

use App\Models\Team;

class UpdateTeamName
{
    public function execute(Team $team, string $name, ?string $slug = null): void
    {
        $team->update(array_filter([
            'name' => $name,
            'slug' => $slug,
        ], fn ($v) => $v !== null));
    }
}
