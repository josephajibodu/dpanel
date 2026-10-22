<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;

class SshKeyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SshKey $sshKey): bool
    {
        return $user->id === $sshKey->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SshKey $sshKey): bool
    {
        return $user->id === $sshKey->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SshKey $sshKey): bool
    {
        return $user->id === $sshKey->user_id;
    }

    /**
     * Determine whether the user can sync the key to the team's servers, which
     * gives them a shell on those servers.
     */
    public function sync(User $user, SshKey $sshKey, Team $team): bool
    {
        return $user->id === $sshKey->user_id
            && $user->hasTeamPermission($team, TeamPermission::AccessServers);
    }

    /**
     * Determine whether the user can revoke the key from the team's servers.
     */
    public function revoke(User $user, SshKey $sshKey, Team $team): bool
    {
        return $user->id === $sshKey->user_id
            && $user->hasTeamPermission($team, TeamPermission::AccessServers);
    }
}
