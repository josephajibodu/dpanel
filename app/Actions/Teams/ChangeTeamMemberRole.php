<?php

namespace App\Actions\Teams;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\User;

class ChangeTeamMemberRole
{
    public function __construct(private RevokeMemberServerAccess $revokeServerAccess) {}

    /**
     * Give the member a new role. Without server access their SSH keys come
     * off this team's servers; without admin server access they come off the
     * server user (which has root access), staying only on isolated sites.
     * That also cleans up keys a member put on the server user before admin
     * access became its own permission.
     */
    public function execute(Team $team, User $member, TeamRole $role): void
    {
        $team->users()->updateExistingPivot($member->id, ['team_role_id' => $role->id]);

        if (! $role->grants(TeamPermission::AccessServers)) {
            $this->revokeServerAccess->execute($team, $member);
        } elseif (! $role->grants(TeamPermission::AccessServersAsAdmin)) {
            $this->revokeServerAccess->execute($team, $member, serverUserOnly: true);
        }
    }
}
