// Ops Center 第二阶段 API
// 说明：Docker、系统资源、磁盘、网络流量统一从这里请求，页面只负责展示。
import request from '@/utils/request'

export interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

export interface DockerContainer {
    id: string
    full_id: string
    name: string
    image: string
    state: string
    status: string
}

export interface DockerStats {
    container_id: string
    available?: boolean
    error?: string
    cpu_percent: number
    memory: {
        usage_bytes: number
        usage_mb: number
        limit_bytes: number
        limit_mb: number
        percent: number
    }
    network: {
        rx_bytes: number
        tx_bytes: number
        rx_mb: number
        tx_mb: number
        interfaces: Record<string, unknown>
    }
    block_io: {
        read_bytes: number
        write_bytes: number
        read_mb: number
        write_mb: number
    }
    pids: number
    read_at: string
}

export interface NetworkSummary {
    status: string
    timestamp: number
    summary: {
        rx_kb_s: number
        tx_kb_s: number
        rx_mb_s: number
        tx_mb_s: number
    }
    interfaces: Record<string, {
        rx_kb_s: number
        tx_kb_s: number
        rx_mb_s: number
        tx_mb_s: number
        rx_packets: number
        tx_packets: number
    }>
}

export interface DiskSummary {
    status?: string
    source?: string
    message?: string | null
    total_gb: number
    used_gb: number
    available_gb: number
    max_usage: number
    checked_at: string
    disks: Array<{
        filesystem: string
        size: number
        used: number
        available: number
        usage: number
        mount: string
    }>
}

export const getDockerContainers = () =>
    request.get<ApiResponse<DockerContainer[]>>('/api/ops/docker/containers')

export const getDockerStats = (id: string) =>
    request.get<ApiResponse<DockerStats>>(`/api/ops/docker/stats/${encodeURIComponent(id)}`)

export const getDockerLogs = (id: string) =>
    request.get<ApiResponse<string>>(`/api/ops/docker/logs/${encodeURIComponent(id)}`)

export const restartDockerContainer = (id: string, confirmText: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/docker/restart/${encodeURIComponent(id)}`, {
        confirm_text: confirmText,
    })

export const startDockerContainer = (id: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/docker/start/${encodeURIComponent(id)}`)

export const stopDockerContainer = (id: string, confirmText: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/docker/stop/${encodeURIComponent(id)}`, {
        confirm_text: confirmText,
    })

export const getNetworkSummary = () =>
    request.get<ApiResponse<NetworkSummary>>('/api/ops/network')

export const getDiskSummary = () =>
    request.get<ApiResponse<DiskSummary>>('/api/ops/system/disk')
