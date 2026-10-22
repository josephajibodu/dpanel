<?php

namespace App\Enums;

/**
 * Everything a team member may be allowed to do inside a team. Authorization
 * checks only ever ask for one of these, never for a role, so roles can be
 * reshaped (or made custom per team) without touching any check.
 *
 * The string values are stable identifiers: custom roles will store them.
 */
enum TeamPermission: string
{
    case View = 'view';
    case Deploy = 'deploy';
    case ManageSites = 'manage_sites';
    case ManageProcesses = 'manage_processes';
    case ManageDatabases = 'manage_databases';
    case ManagePhp = 'manage_php';
    case RunCommands = 'run_commands';
    case ViewSecrets = 'view_secrets';
    case AccessServers = 'access_servers';
    case AccessServersAsAdmin = 'access_servers_as_admin';
    case RunBackups = 'run_backups';
    case RestoreBackups = 'restore_backups';
    case ManageServers = 'manage_servers';
    case ManageProviders = 'manage_providers';
    case ManageMembers = 'manage_members';
    case ManageTeamSettings = 'manage_team_settings';
    case ManageOwners = 'manage_owners';
    case DeleteTeam = 'delete_team';

    /**
     * Permissions only an Owner holds. A role that grants ManageOwners counts
     * as owner-level: only someone who also holds it may grant that role or
     * change, remove or cancel the invitation of anyone holding it.
     *
     * @return list<self>
     */
    public static function ownerOnly(): array
    {
        return [self::ManageOwners, self::DeleteTeam];
    }
}
