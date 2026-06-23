export interface SiteDomainDnsRecord {
    type: string;
    name: string;
    value: string;
}

export interface SiteDomain {
    id: number;
    ulid: string;
    hostname: string;
    type: string;
    type_label: string;
    is_primary: boolean;
    wildcard_enabled: boolean;
    www_redirect: string;
    www_redirect_label: string;
    is_enabled: boolean;
    status: 'active' | 'deleting';
    is_verified: boolean;
    verified_at: string | null;
    has_ssl: boolean;
    ssl_enabled_at: string | null;
    dns_records: SiteDomainDnsRecord[];
    created_at: string;
    updated_at: string;
}
