export interface Deployment {
    id: number;
    ulid: string;
    commit_hash: string | null;
    commit_message: string | null;
    commit_author: string | null;
    status: 'pending' | 'running' | 'finished' | 'failed' | 'cancelled';
    status_label: string;
    status_color: 'gray' | 'blue' | 'green' | 'red' | 'orange';
    triggered_by: 'manual' | 'webhook' | 'api';
    started_at: string | null;
    finished_at: string | null;
    duration_seconds: number | null;
    created_at: string;
    updated_at: string;
}
