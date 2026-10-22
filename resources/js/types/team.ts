export type TeamRoleKey = 'owner' | 'admin' | 'developer' | 'viewer';

/** Mirrors App\Enums\TeamPermission; TeamPermissionTypesTest fails if they drift apart. */
export type TeamPermission =
    | 'view'
    | 'deploy'
    | 'manage_sites'
    | 'manage_processes'
    | 'manage_databases'
    | 'manage_php'
    | 'run_commands'
    | 'view_secrets'
    | 'access_servers'
    | 'run_backups'
    | 'restore_backups'
    | 'manage_servers'
    | 'manage_providers'
    | 'manage_members'
    | 'manage_team_settings'
    | 'manage_owners'
    | 'delete_team';

export interface Team {
    id: number;
    name: string;
    slug: string;
    personal_team: boolean;
    user_id: number;
    created_at: string;
    updated_at: string;
}

export interface TeamMember {
    id: number;
    name: string;
    email: string;
    role: TeamRoleKey;
}

export interface TeamInvitation {
    id: number;
    team_id: number;
    email: string;
    role: TeamRoleKey;
    created_at: string;
}
