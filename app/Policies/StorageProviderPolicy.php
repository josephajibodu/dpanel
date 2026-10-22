<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\User;

class StorageProviderPolicy
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
    public function view(User $user, StorageProvider $storageProvider): bool
    {
        return $user->hasTeamPermission($storageProvider->team, TeamPermission::View);
    }

    /**
     * Determine whether the user can create models in the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageProviders);
    }

    /**
     * Determine whether the user can update (or re-validate) the model.
     */
    public function update(User $user, StorageProvider $storageProvider): bool
    {
        return $user->hasTeamPermission($storageProvider->team, TeamPermission::ManageProviders);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StorageProvider $storageProvider): bool
    {
        return $user->hasTeamPermission($storageProvider->team, TeamPermission::ManageProviders);
    }
}
