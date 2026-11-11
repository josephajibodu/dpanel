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
     * Determine whether the user can create a site that isn't isolated. Such
     * a site runs as the server user, who can sudo, so this needs the same
     * permission as a shell as that user.
     */
    public function createWithoutIsolation(User $user, Server $server): bool
    {
        return $this->create($user, $server)
            && $user->hasTeamPermission($server->team, TeamPermission::AccessServersAsAdmin);
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
     * Determine whether the user can deploy the site, cancel a deployment or
     * roll one back. None of these change what the site runs.
     */
    public function deploy(User $user, Site $site): bool
    {
        return $user->hasTeamPermission($site->server->team, TeamPermission::Deploy);
    }

    /**
     * Determine whether the user can change what a deploy uses: the deploy
     * script, the environment file and auto-deploy.
     */
    public function changeDeploySettings(User $user, Site $site): bool
    {
        return $this->deploy($user, $site) && $this->mayChooseWhatRuns($user, $site);
    }

    /**
     * Determine whether the user can point the site at another repository or branch.
     */
    public function changeRepository(User $user, Site $site): bool
    {
        return $this->update($user, $site) && $this->mayChooseWhatRuns($user, $site);
    }

    /**
     * Determine whether the user can run commands in the site's directory.
     */
    public function runCommands(User $user, Site $site): bool
    {
        return $user->hasTeamPermission($site->server->team, TeamPermission::RunCommands)
            && $this->mayChooseWhatRuns($user, $site);
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

    /**
     * Whether the user may choose what runs as the site's Linux user. An
     * isolated site's user can only reach that site. Every other site runs
     * as the server user, who can sudo, so choosing what it runs needs the
     * same permission as a shell as that user.
     */
    private function mayChooseWhatRuns(User $user, Site $site): bool
    {
        return $site->isIsolated()
            || $user->hasTeamPermission($site->server->team, TeamPermission::AccessServersAsAdmin);
    }
}
