<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

class ServerPolicy
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
    public function view(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::View);
    }

    /**
     * Determine whether the user can ask for a fresh connection check or
     * metrics reading. Both only read from the server and change nothing, so
     * anyone who can see the server may refresh what they see.
     */
    public function refreshStatus(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::View);
    }

    /**
     * Determine whether the user can create servers in the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageServers);
    }

    /**
     * Determine whether the user can provision or restart services on the server.
     */
    public function update(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::ManageServers);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::ManageServers);
    }

    /**
     * Determine whether the user can forget the server's recorded SSH host
     * key, so the next connection trusts whatever key the server presents.
     */
    public function resetHostKey(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::ManageServers);
    }

    /**
     * Determine whether the user can manage the server's databases and database users.
     */
    public function manageDatabases(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::ManageDatabases);
    }

    /**
     * Determine whether the user can install PHP versions and change PHP settings.
     */
    public function managePhp(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::ManagePhp);
    }

    /**
     * Determine whether the user can manage the server's workers and cron jobs.
     */
    public function manageProcesses(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::ManageProcesses);
    }

    /**
     * Determine whether the user can take backups and edit backup schedules.
     */
    public function runBackups(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::RunBackups);
    }

    /**
     * Determine whether the user can restore a backup over a live database.
     */
    public function restoreBackups(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::RestoreBackups);
    }

    /**
     * Determine whether the user can delete a backup. A deleted backup cannot
     * be brought back, so this needs more than taking one.
     */
    public function deleteBackups(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::DeleteBackups);
    }

    /**
     * Determine whether the user can see secrets such as database passwords and backup files.
     */
    public function viewSecrets(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team, TeamPermission::ViewSecrets);
    }
}
