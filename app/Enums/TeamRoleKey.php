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
            self::Owner, self::Admin => TeamPermission::cases(),
            self::Developer => array_values(array_filter(
                TeamPermission::cases(),
                fn (TeamPermission $permission): bool => ! in_array($permission, [
                    TeamPermission::RestoreBackups,
                    TeamPermission::ManageServers,
                    TeamPermission::ManageProviders,
                ], true),
            )),
            self::Viewer => [TeamPermission::View],
        };
    }
}
