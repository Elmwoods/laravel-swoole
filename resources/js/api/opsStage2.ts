// Ops Center 第二阶段 API
// 说明：Docker、系统资源、磁盘、网络流量统一从这里请求，页面只负责展示。
import request from '@/utils/request'

/**
 * 后端统一响应结构
 * code：业务状态码；message：提示文案；data：数据载荷；timestamp：服务端时间戳
 */
export interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

/**
 * Docker 容器基础信息
 * id：短 ID；full_id：完整 ID；name/image：名称与镜像；state/status：运行状态
 */
export interface DockerContainer {
    id: string
    full_id: string
    name: string
    image: string
    state: string
    status: string
}

/**
 * 单个容器的实时资源统计
 * CPU 占用、内存（用量/上限/百分比）、网络收发、块设备读写、进程数与采样时间；
 * available/error 用于标识统计是否可用
 */
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

/**
 * 网络流量摘要
 * summary：整机收发速率（KB/s 与 MB/s）；interfaces：按网卡拆分的速率与收发包数
 */
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

/**
 * 磁盘使用摘要
 * 整机容量（总量/已用/可用 GB）、最高使用率，以及各挂载点/文件系统的明细
 */
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

/** GET /api/ops/docker/containers —— 获取 Docker 容器列表 */
export const getDockerContainers = () =>
    request.get<ApiResponse<DockerContainer[]>>('/api/ops/docker/containers')

/** GET /api/ops/docker/stats/{id} —— 获取指定容器的实时资源统计（id 做 URL 编码） */
export const getDockerStats = (id: string) =>
    request.get<ApiResponse<DockerStats>>(`/api/ops/docker/stats/${encodeURIComponent(id)}`)

/** GET /api/ops/docker/logs/{id} —— 获取指定容器的日志文本（id 做 URL 编码） */
export const getDockerLogs = (id: string) =>
    request.get<ApiResponse<string>>(`/api/ops/docker/logs/${encodeURIComponent(id)}`)

/** POST /api/ops/docker/restart/{id} —— 重启指定容器；confirmText 为二次确认文本 */
export const restartDockerContainer = (id: string, confirmText: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/docker/restart/${encodeURIComponent(id)}`, {
        confirm_text: confirmText,
    })

/** POST /api/ops/docker/start/{id} —— 启动指定容器 */
export const startDockerContainer = (id: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/docker/start/${encodeURIComponent(id)}`)

/** POST /api/ops/docker/stop/{id} —— 停止指定容器；confirmText 为二次确认文本 */
export const stopDockerContainer = (id: string, confirmText: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/docker/stop/${encodeURIComponent(id)}`, {
        confirm_text: confirmText,
    })

/** GET /api/ops/network —— 获取网络流量摘要（整机与各网卡速率） */
export const getNetworkSummary = () =>
    request.get<ApiResponse<NetworkSummary>>('/api/ops/network')

/** GET /api/ops/system/disk —— 获取磁盘使用摘要（整机容量与各挂载点明细） */
export const getDiskSummary = () =>
    request.get<ApiResponse<DiskSummary>>('/api/ops/system/disk')
