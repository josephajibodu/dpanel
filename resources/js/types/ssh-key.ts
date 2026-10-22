export type SshKeyServerStatus = 'pending' | 'syncing' | 'synced' | 'revoking' | 'failed';

export interface SshKeyServer {
    id: number;
    ulid: string;
    name: string;
    /** Set when the key is on an isolated site's own user rather than the server user. */
    site_id: number | null;
    system_user: string;
    status: SshKeyServerStatus;
    synced_at: string | null;
}

export interface IsolatedSiteTarget {
    id: number;
    domain: string;
    system_user: string;
    server_id: number;
    server_name: string;
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
