export interface ProviderAccount {
    id: number;
    ulid: string;
    provider: 'digitalocean' | 'hetzner' | 'vultr';
    provider_label: string;
    name: string;
    is_valid: boolean;
    validated_at: string | null;
    servers_count?: number;
    created_at: string;
    updated_at: string;
}

export interface Provider {
    value: string;
    label: string;
}
