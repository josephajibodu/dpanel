export interface BackupSchedule {
    id: number;
    storage_provider_id: number;
    frequency: 'hourly' | 'daily' | 'weekly';
    retention_count: number;
    enabled: boolean;
    next_run_at: string | null;
}

export interface Backup {
    id: number;
    status:
        | 'pending'
        | 'dumping'
        | 'verifying'
        | 'uploading'
        | 'completed'
        | 'failed';
    triggered_by: 'scheduled' | 'manual';
    triggered_by_user: string | null;
    size_bytes: number | null;
    verified_at: string | null;
    error_message: string | null;
    restore_status: 'running' | 'completed' | 'failed' | null;
    restored_at: string | null;
    restore_error: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string;
}
