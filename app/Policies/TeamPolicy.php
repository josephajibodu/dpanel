<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamRole;
use App\Models\User;

/**
 * Who may manage a team and its members. Plain abilities come from the
 * actor's permissions; the rules about whom they may act on (never
 * themselves, never the team creator, never someone above their reach) all
 * live here, so every route and page asks the same question.
 */
class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageTeamSettings);
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::DeleteTeam) && ! $team->personal_team;
    }

    /**
     * Whether the user may invite someone with the given role. Without a role,
     * whether they may invite anyone at all.
     */
    public function inviteMember(User $user, Team $team, ?TeamRole $role = null): bool
    {
        if ($role === null) {
            return $user->hasTeamPermission($team, TeamPermission::ManageMembers);
        }

        return $this->canGrant($user, $team, $role);
    }

    /**
     * Whether the user may give the member the given role. Without a role,
     * whether they may change this member's role at all.
     */
    public function changeRole(User $user, Team $team, User $member, ?TeamRole $role = null): bool
    {
        return $this->canActOnMember($user, $team, $member)
            && ($role === null || $this->canGrant($user, $team, $role));
    }

    public function removeMember(User $user, Team $team, User $member): bool
    {
        return $this->canActOnMember($user, $team, $member);
    }

    /**
     * Any member may leave a team, except the team creator (the team would be
     * left without its owner) and the owner of a personal team. Other Owners
     * may leave even if they are the last one, because the creator remains.
     */
    public function leave(User $user, Team $team): bool
    {
        return ! $team->personal_team
            && ! $team->userIsOwner($user)
            && $user->teams()->whereKey($team->id)->exists();
    }

    /**
     * Cancelling an invitation is held to the same bar as granting its role,
     * so an Admin cannot undo an Owner's decision to invite another Owner.
     */
    public function cancelInvitation(User $user, Team $team, TeamInvitation $invitation): bool
    {
        return $invitation->team_id === $team->id
            && $invitation->teamRole !== null
            && $this->canGrant($user, $team, $invitation->teamRole);
    }

    /**
     * The user may hand out this role: they manage members, and the role is
     * not owner-level unless they may manage owners themselves.
     */
    private function canGrant(User $user, Team $team, TeamRole $role): bool
    {
        if (! $user->hasTeamPermission($team, TeamPermission::ManageMembers)) {
            return false;
        }

        return ! $role->grants(TeamPermission::ManageOwners)
            || $user->hasTeamPermission($team, TeamPermission::ManageOwners);
    }

    /**
     * The user may change or remove this member: it is someone else, not the
     * team creator, a member of this team, and their current role is one the
     * user could have granted.
     */
    private function canActOnMember(User $user, Team $team, User $member): bool
    {
        if ($user->is($member) || $team->userIsOwner($member)) {
            return false;
        }

        $memberRole = $member->teamRole($team);

        return $memberRole !== null && $this->canGrant($user, $team, $memberRole);
    }
}
