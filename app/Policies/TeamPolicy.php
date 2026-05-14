<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    public function update(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    public function addMember(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    public function removeMember(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->ownsTeam($team) && ! $team->personal_team;
    }
}
