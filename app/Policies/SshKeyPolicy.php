<?php

namespace App\Policies;

use App\Models\SshKey;
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
     * Determine whether the user can sync the key to servers.
     */
    public function sync(User $user, SshKey $sshKey): bool
    {
        return $user->id === $sshKey->user_id;
    }

    /**
     * Determine whether the user can revoke the key from servers.
     */
    public function revoke(User $user, SshKey $sshKey): bool
    {
        return $user->id === $sshKey->user_id;
    }
}
