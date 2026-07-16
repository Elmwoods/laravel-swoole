// Ops Center 第一阶段 API
// 说明：所有页面统一从这里请求后端，避免各个 Vue 页面散落硬编码 URL。
import request from '@/utils/request'

export interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

export interface OctaneWorker {
    pid: number
    parent_pid: number
    cpu_percent: number
    memory_percent: number
    running_time: string
    command: string
}

export interface OctaneStatus {
    running: boolean
    server: string
    configured_workers: number
    configured_task_workers: number
    master_pid: number | null
    process_count: number
    workers: OctaneWorker[]
    state_file: {
        path: string
        exists: boolean
    }
    checked_at: string
}

export interface RedisSummary {
    redis_version: string
    redis_mode: string | null
    role: string | null
    uptime_in_seconds: number
    uptime_in_days: number
    connected_clients: number
    blocked_clients: number
    rejected_connections: number
    used_memory: number
    used_memory_human: string
    used_memory_rss_human: string
    used_memory_peak_human: string
    mem_fragmentation_ratio: number
    instantaneous_ops_per_sec: number
    total_commands_processed: number
    total_connections_received: number
    keyspace_hits: number
    keyspace_misses: number
    used_cpu_user: number
    used_cpu_sys: number
    rdb_last_bgsave_status: string | null
    aof_enabled: number
}

export interface QueueMetric {
    name: string
    pending: number
    delayed: number
    reserved: number
}

export interface QueueWorker {
    pid: number
    cpu_percent: number
    memory_percent: number
    running_time: string
    command: string
}

export interface QueueSummary {
    queues: QueueMetric[]
    failed_jobs: {
        count: number
        latest: Array<{
            id: number
            connection: string
            queue: string
            failed_at: string
        }>
    }
    workers: {
        queue_connection: string
        running: boolean
        process_count: number
        processes: QueueWorker[]
    }
    checked_at: string
}

export interface SupervisorService {
    name: string
    status: string
    description: string
    running: boolean
}

export interface SupervisorStatus {
    services: SupervisorService[]
}

export const getOctaneStatus = () =>
    request.get<ApiResponse<OctaneStatus>>('/api/ops/octane/status')

export const reloadOctane = (confirmText: string) =>
    request.post<ApiResponse<{ reloaded: boolean }>>('/api/ops/octane/reload', {
        confirm_text: confirmText,
    })

export const restartOctane = (confirmText: string) =>
    request.post<ApiResponse<{ restarted: boolean }>>('/api/ops/octane/restart', {
        confirm_text: confirmText,
    })

export const getRedisSummary = () =>
    request.get<ApiResponse<RedisSummary>>('/api/ops/redis/summary')

export const getQueueSummary = () =>
    request.get<ApiResponse<QueueSummary>>('/api/ops/queue/summary')

export const getSupervisorStatus = () =>
    request.get<ApiResponse<SupervisorStatus>>('/api/ops/supervisor/status')

export const startSupervisor = (name: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/supervisor/start/${encodeURIComponent(name)}`)

export const stopSupervisor = (name: string, confirmText: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/supervisor/stop/${encodeURIComponent(name)}`, {
        confirm_text: confirmText,
    })

export const restartSupervisor = (name: string, confirmText: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/supervisor/restart/${encodeURIComponent(name)}`, {
        confirm_text: confirmText,
    })

export const getSupervisorLogs = (name: string) =>
    request.get<ApiResponse<{ service: string; logs: string }>>(
        `/api/ops/supervisor/logs/${encodeURIComponent(name)}`,
    )
