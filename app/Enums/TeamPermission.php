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
    case RunBackups = 'run_backups';
    case RestoreBackups = 'restore_backups';
    case ManageServers = 'manage_servers';
    case ManageProviders = 'manage_providers';

}
