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
    role: 'owner' | 'member';
}

export interface TeamInvitation {
    id: number;
    team_id: number;
    email: string;
    role: 'owner' | 'member';
    created_at: string;
}
