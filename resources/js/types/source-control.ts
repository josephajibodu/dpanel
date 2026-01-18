export interface SourceControlAccount {
    id: number;
    ulid: string;
    provider: 'github' | 'gitlab' | 'bitbucket';
    provider_label: string;
    provider_user_id: string;
    provider_username: string;
    name: string;
    email: string | null;
    avatar_url: string | null;
    connected_at: string;
    token_expires_at: string | null;
    is_token_expired: boolean;
    created_at: string;
    updated_at: string;
}

export interface RepositoryProvider {
    value: string;
    label: string;
}
