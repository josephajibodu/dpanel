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
    status:
        | 'pending'
        | 'creating'
        | 'provisioning'
        | 'active'
        | 'error'
        | 'deleting';
    status_label: string;
    status_color: 'gray' | 'blue' | 'yellow' | 'green' | 'red' | 'orange';
    error_message: string | null;
    provisioning_step: ProvisioningStep | null;
    provisioning_steps: ProvisioningStep[];
    type: ServerTypeValue | null;
    type_label: string | null;
    web_server: 'nginx' | 'caddy' | null;
    php_version: string | null;
    has_php: boolean;
    supports_sites: boolean;
    database_type: 'mysql' | 'postgresql' | 'mariadb' | null;
    accepts_connections: boolean;
    ssh_port: number;
    sites_count?: number;
    provisioned_at: string | null;
    last_ssh_connection_at: string | null;
    created_at: string;
    updated_at: string;
}

export type ServerTypeValue =
    | 'app'
    | 'web'
    | 'worker'
    | 'database'
    | 'cache'
    | 'loadbalancer';

/** A server type and the services it ticks by default. */
export interface ServerTypeOption {
    value: ServerTypeValue;
    label: string;
    description: string;
    services: {
        php: boolean;
        web_server: boolean;
        database: boolean;
        redis: boolean;
    };
}

export interface ProviderRegion {
    slug: string;
    name: string;
    /** Monthly price of the public IPv4 address, when the provider bills it separately. */
    ipv4_price_monthly: number | null;
}

export interface ProviderSize {
    slug: string;
    /** The provider's own name for the size, e.g. "CPX 22". */
    label: string | null;
    /** The family the size belongs to, e.g. "Regular performance". */
    category: string | null;
    vcpus: number;
    memory: number;
    disk: number;
    /** Lowest monthly price before tax; used when a region has no price of its own. */
    price_monthly: number;
    /** Monthly price before tax per region slug, where it differs by region. */
    region_prices: Record<string, number>;
    /** Region slugs the size can be created in; null means every region. */
    regions: string[] | null;
    currency: string;
    description: string;
}

export interface ServerDatabase {
    id: number;
    server_id: number;
    server_name?: string;
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

/**
 * What the Connect dialog needs for one server database, taken from the server
 * the database lives on.
 */
export interface DatabaseConnection {
    server: Pick<
        Server,
        'id' | 'name' | 'ip_address' | 'ssh_port' | 'database_type'
    >;
    database: ServerDatabase;
    databaseUsers: DatabaseUser[];
    sshUser: string;
    hasSshKey: boolean;
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
    restart_on_deploy: boolean;
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
    system_user: string;
}
