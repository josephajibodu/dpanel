export interface StorageProvider {
    id: number;
    ulid: string;
    type: 'cloudflare_r2' | 's3';
    type_label: string;
    name: string;
    bucket: string | null;
    is_valid: boolean;
    validated_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface StorageProviderTypeOption {
    value: string;
    label: string;
    fields: string[];
}
