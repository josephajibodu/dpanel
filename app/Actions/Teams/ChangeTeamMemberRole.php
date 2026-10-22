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
     * Give the member a new role. If it takes away their server access, their
     * SSH keys come off this team's servers too.
     */
    public function execute(Team $team, User $member, TeamRole $role): void
    {
        $hadServerAccess = $member->teamRole($team)?->grants(TeamPermission::AccessServers) ?? false;

        $team->users()->updateExistingPivot($member->id, ['team_role_id' => $role->id]);

        if ($hadServerAccess && ! $role->grants(TeamPermission::AccessServers)) {
            $this->revokeServerAccess->execute($team, $member);
        }
    }
}
