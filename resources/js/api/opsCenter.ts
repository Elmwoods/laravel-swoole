// Ops Center 第一阶段 API
// 说明：所有页面统一从这里请求后端，避免各个 Vue 页面散落硬编码 URL。
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
 * 单个 Octane 工作进程
 * 包含进程 PID/父 PID、CPU/内存占用百分比、运行时长与启动命令
 */
export interface OctaneWorker {
    pid: number
    parent_pid: number
    cpu_percent: number
    memory_percent: number
    running_time: string
    command: string
}

/**
 * Octane 运行状态总览
 * 是否运行、服务器类型、配置的 worker/task worker 数、主进程 PID、进程数量、
 * worker 明细以及状态文件信息与检查时间
 */
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

/**
 * Redis 运行摘要
 * 直接映射 Redis INFO 的关键指标：版本/模式/角色、运行时长、连接数、内存占用与碎片率、
 * 每秒操作数、命中/未命中次数、CPU 用量以及持久化（RDB/AOF）状态
 */
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

/**
 * 单个队列的堆积指标
 * name：队列名；pending 待处理 / delayed 延迟 / reserved 已保留（处理中）作业数
 */
export interface QueueMetric {
    name: string
    pending: number
    delayed: number
    reserved: number
}

/**
 * 单个队列消费进程
 * 进程 PID、CPU/内存占用、运行时长与启动命令
 */
export interface QueueWorker {
    pid: number
    cpu_percent: number
    memory_percent: number
    running_time: string
    command: string
}

/**
 * 队列系统摘要
 * 各队列堆积指标、失败作业统计与最近失败列表，以及消费进程运行情况
 */
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

/**
 * 单个 Supervisor 托管服务
 * name：服务名；status：原始状态字符串；description：描述；running：是否在运行
 */
export interface SupervisorService {
    name: string
    status: string
    description: string
    running: boolean
}

/**
 * Supervisor 状态总览
 * 托管服务列表
 */
export interface SupervisorStatus {
    services: SupervisorService[]
}

/** GET /api/ops/octane/status —— 获取 Octane 运行状态与 worker 明细 */
export const getOctaneStatus = () =>
    request.get<ApiResponse<OctaneStatus>>('/api/ops/octane/status')

/** POST /api/ops/octane/reload —— 平滑重载 Octane worker；confirmText 为二次确认文本 */
export const reloadOctane = (confirmText: string) =>
    request.post<ApiResponse<{ reloaded: boolean }>>('/api/ops/octane/reload', {
        confirm_text: confirmText,
    })

/** POST /api/ops/octane/restart —— 重启 Octane 服务；confirmText 为二次确认文本 */
export const restartOctane = (confirmText: string) =>
    request.post<ApiResponse<{ restarted: boolean }>>('/api/ops/octane/restart', {
        confirm_text: confirmText,
    })

/** GET /api/ops/redis/summary —— 获取 Redis 运行摘要（INFO 关键指标） */
export const getRedisSummary = () =>
    request.get<ApiResponse<RedisSummary>>('/api/ops/redis/summary')

/** GET /api/ops/queue/summary —— 获取队列堆积、失败作业与消费进程摘要 */
export const getQueueSummary = () =>
    request.get<ApiResponse<QueueSummary>>('/api/ops/queue/summary')

/** GET /api/ops/supervisor/status —— 获取 Supervisor 托管服务列表及运行状态 */
export const getSupervisorStatus = () =>
    request.get<ApiResponse<SupervisorStatus>>('/api/ops/supervisor/status')

/** POST /api/ops/supervisor/start/{name} —— 启动指定 Supervisor 服务（name 做 URL 编码防特殊字符） */
export const startSupervisor = (name: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/supervisor/start/${encodeURIComponent(name)}`)

/** POST /api/ops/supervisor/stop/{name} —— 停止指定 Supervisor 服务；confirmText 为二次确认文本 */
export const stopSupervisor = (name: string, confirmText: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/supervisor/stop/${encodeURIComponent(name)}`, {
        confirm_text: confirmText,
    })

/** POST /api/ops/supervisor/restart/{name} —— 重启指定 Supervisor 服务；confirmText 为二次确认文本 */
export const restartSupervisor = (name: string, confirmText: string) =>
    request.post<ApiResponse<unknown>>(`/api/ops/supervisor/restart/${encodeURIComponent(name)}`, {
        confirm_text: confirmText,
    })

/** GET /api/ops/supervisor/logs/{name} —— 获取指定 Supervisor 服务的日志文本 */
export const getSupervisorLogs = (name: string) =>
    request.get<ApiResponse<{ service: string; logs: string }>>(
        `/api/ops/supervisor/logs/${encodeURIComponent(name)}`,
    )
