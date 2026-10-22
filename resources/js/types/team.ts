export type TeamRoleKey = 'owner' | 'admin' | 'developer' | 'viewer';

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
