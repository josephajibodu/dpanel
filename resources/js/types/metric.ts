export interface ServerMetric {
    load: number;
    memory_total: number;
    memory_used: number;
    memory_free: number;
    disk_total: number;
    disk_used: number;
    disk_free: number;
    collected_at: string;
}
