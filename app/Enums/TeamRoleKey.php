<?php

namespace App\Enums;

/**
 * The built-in team roles. Each one is a row in `team_roles` (with a NULL
 * team_id), but its permission set lives here in code, so a newly added
 * permission reaches the built-in roles without a data migration.
 */
enum TeamRoleKey: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Developer = 'developer';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Developer => 'Developer',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * @return list<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Admin => self::allExcept(TeamPermission::ownerOnly()),
            self::Developer => self::allExcept([
                ...TeamPermission::ownerOnly(),
                TeamPermission::AccessServersAsAdmin,
                TeamPermission::RestoreBackups,
                TeamPermission::ManageServers,
                TeamPermission::ManageProviders,
                TeamPermission::ManageMembers,
                TeamPermission::ManageTeamSettings,
            ]),
            self::Viewer => [TeamPermission::View],
        };
    }

    /**
     * @param  list<TeamPermission>  $excluded
     * @return list<TeamPermission>
     */
    private static function allExcept(array $excluded): array
    {
        return array_values(array_filter(
            TeamPermission::cases(),
            fn (TeamPermission $permission): bool => ! in_array($permission, $excluded, true),
        ));
    }
}
