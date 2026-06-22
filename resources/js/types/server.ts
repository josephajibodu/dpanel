export interface ProvisioningStep {
    value: number;
    label: string;
    description: string;
}

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
    error_message: string | null;
    provisioning_step: ProvisioningStep | null;
    provisioning_steps: ProvisioningStep[];
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

export interface ServerDatabase {
    id: number;
    server_id: number;
    name: string;
    collation: string | null;
    charset: string | null;
    status: string;
    created_at: string;
    updated_at: string;
}

export interface DatabaseUser {
    id: number;
    server_id: number;
    username: string;
    databases: string[];
    permission: string | null;
    host: string | null;
    status: string;
    created_at: string;
    updated_at: string;
}

export interface WorkerSite {
    id: number;
    domain: string;
}

export interface Worker {
    id: number;
    server_id: number;
    site_id: number | null;
    command: string;
    user: string;
    status: string;
    name: string;
    numprocs: number;
    auto_start: boolean;
    auto_restart: boolean;
    redirect_stderr: boolean | null;
    stdout_logfile: string | null;
    site?: WorkerSite | null;
    created_at: string;
    updated_at: string;
}

export interface CronJobSite {
    id: number;
    domain: string;
}

export interface CronJob {
    id: number;
    server_id: number;
    site_id: number | null;
    command: string;
    user: string;
    frequency: string;
    hidden: boolean;
    status: string;
    site?: CronJobSite | null;
    created_at: string;
    updated_at: string;
}

export interface ProcessSite {
    id: number;
    domain: string;
}
