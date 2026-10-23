<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;

class SitePolicy
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
    public function view(User $user, Site $site): bool
    {
        return $user->hasTeamPermission($site->server->team, TeamPermission::View);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::ManageSites) && $server->supportsSites();
    }

    /**
     * Determine whether the user can change the site, its domains and its nginx files.
     */
    public function update(User $user, Site $site): bool
    {
        return $user->hasTeamPermission($site->server->team, TeamPermission::ManageSites);
    }

    /**
     * Determine whether the user can move an existing site to its own Linux user.
     */
    public function isolate(User $user, Site $site): bool
    {
        return $user->hasTeamPermission($site->server->team, TeamPermission::ManageSites);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Site $site): bool
    {
        return $user->hasTeamPermission($site->server->team, TeamPermission::ManageSites);
    }

    /**
     * Determine whether the user can deploy the site and change what a deploy uses
     * (the deploy script, the environment file and auto-deploy).
     */
    public function deploy(User $user, Site $site): bool
    {
        return $user->hasTeamPermission($site->server->team, TeamPermission::Deploy);
    }

    /**
     * Determine whether the user can run commands in the site's directory.
     */
    public function runCommands(User $user, Site $site): bool
    {
        return $user->hasTeamPermission($site->server->team, TeamPermission::RunCommands);
    }

    /**
     * Determine whether the user can take backups and edit the backup schedule.
     */
    public function runBackups(User $user, Site $site): bool
    {
        return $user->hasTeamPermission($site->server->team, TeamPermission::RunBackups);
    }

    /**
     * Determine whether the user can delete one of the site's backups. A
     * deleted backup cannot be brought back, so this needs more than taking one.
     */
    public function deleteBackups(User $user, Site $site): bool
    {
        return $user->hasTeamPermission($site->server->team, TeamPermission::DeleteBackups);
    }

    /**
     * Determine whether the user can see the site's secrets: its environment
     * file, command output and backup files.
     */
    public function viewSecrets(User $user, Site $site): bool
    {
        return $user->hasTeamPermission($site->server->team, TeamPermission::ViewSecrets);
    }
}
