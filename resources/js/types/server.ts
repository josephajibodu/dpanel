export interface Server {
    id: number;
    ulid: string;
    name: string;
    provider: 'digitalocean' | 'hetzner' | 'vultr';
    provider_label: string;
    provider_account?: {
        id: number;
        name: string;
        provider: string;
    };
    region: string;
    size: string;
    ip_address: string | null;
    private_ip_address: string | null;
    status: 'pending' | 'creating' | 'provisioning' | 'active' | 'error' | 'deleting';
    status_label: string;
    status_color: 'gray' | 'blue' | 'yellow' | 'green' | 'red' | 'orange';
    php_version: string;
    database_type: string;
    ssh_port: number;
    sites_count?: number;
    provisioned_at: string | null;
    last_ssh_connection_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface ProviderRegion {
    slug: string;
    name: string;
}

export interface ProviderSize {
    slug: string;
    vcpus: number;
    memory: number;
    disk: number;
    price_monthly: number;
    description: string;
}
