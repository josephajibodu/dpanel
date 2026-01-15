export type SshKeyServerStatus = 'pending' | 'syncing' | 'synced' | 'revoking' | 'failed';

export interface SshKeyServer {
    id: number;
    ulid: string;
    name: string;
    status: SshKeyServerStatus;
    synced_at: string | null;
}

export interface SshKey {
    id: number;
    ulid: string;
    name: string;
    fingerprint: string;
    public_key_preview: string;
    servers?: SshKeyServer[];
    servers_count?: number;
    created_at: string;
    updated_at: string;
}
