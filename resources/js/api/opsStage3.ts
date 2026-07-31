// Ops Center 第三阶段 API
// 说明：日志中心统一从这里请求，页面只负责展示和交互。
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
 * 日志文件读取结果
 * source：日志来源；path/exists/readable：文件路径与可读性；mode：tail 尾读 / full 全量；
 * lines 原始行；entries 结构化条目；facets 聚合维度；pagination 分页；count/entry_count 计数
 */
export interface LogFileResult {
    source: string
    path: string | null
    exists: boolean
    readable: boolean
    mode?: 'tail' | 'full'
    message?: string
    lines: string[]
    entries?: LogEntry[]
    facets?: LogFacets
    pagination?: LogPagination
    count: number
    entry_count?: number
    checked_at: string
}

/**
 * 日志分页信息
 * 当前页/每页数量/总数/末页，has_more 标记是否还有更多
 */
export interface LogPagination {
    current_page: number
    per_page: number
    total: number
    last_page: number
    has_more: boolean
}

/**
 * 日志聚合维度
 * levels：各日志级别的数量；timeline：按时间桶聚合的数量，供筛选与迷你图展示
 */
export interface LogFacets {
    source: string
    levels: Array<{
        name: string
        total: number
    }>
    timeline: Array<{
        bucket: string
        total: number
    }>
}

/**
 * 结构化日志条目
 * 解析后的单条日志：时间、级别、摘要与完整内容；preview_lines/line_count/truncated 支持折叠展示长日志
 */
export interface LogEntry {
    time: string | null
    level: string
    summary: string
    content: string
    lines: string[]
    preview_lines?: string[]
    line_count?: number
    truncated?: boolean
}

/**
 * Redis 慢日志条目
 * 发生时间、耗时（毫秒）、执行命令与客户端信息
 */
export interface RedisSlowLogEntry {
    id: number
    occurred_at: string
    duration_ms: number
    command: string
    client: string
    client_name: string
}

/**
 * Redis 慢日志查询结果
 * available 标记功能是否可用；entries 慢日志条目；count 数量；可选分页与检查时间
 */
export interface RedisSlowLogResult {
    source: string
    available: boolean
    message?: string
    entries: RedisSlowLogEntry[]
    count: number
    pagination?: LogPagination
    checked_at: string
}

/**
 * 系统日志来源
 * key：来源标识；path：文件路径；exists/readable：是否存在与可读
 */
export interface SystemLogSource {
    key: string
    path: string
    exists: boolean
    readable: boolean
}

/**
 * 系统日志来源列表结果
 * 可选择的系统日志文件来源集合
 */
export interface SystemLogSourcesResult {
    sources: SystemLogSource[]
}

/**
 * 日志查询参数
 * lines/tail 读取行数与尾读行数、mode 读取模式、分页、keyword/level/from/to 过滤条件，
 * 以及 source（系统日志来源）与 container（Docker 容器）定位
 */
export interface LogQuery {
    lines: number
    mode?: 'tail' | 'full'
    tail?: number
    page?: number
    per_page?: number
    keyword?: string
    level?: string
    from?: string
    to?: string
    source?: string
    container?: string
}

// 将 LogQuery 归一化为请求 query 参数：空值统一转为 undefined，避免把无意义的空串/0 传给后端
const params = (query: LogQuery) => ({
    lines: query.lines,
    mode: query.mode || undefined,
    tail: query.tail || undefined,
    page: query.page || undefined,
    per_page: query.per_page || undefined,
    keyword: query.keyword || undefined,
    level: query.level || undefined,
    from: query.from || undefined,
    to: query.to || undefined,
    source: query.source || undefined,
    container: query.container || undefined,
})

/** GET /api/ops/logs/laravel —— 读取 Laravel 应用日志；query 控制读取范围与过滤条件 */
export const getLaravelLogs = (query: LogQuery) =>
    request.get<ApiResponse<LogFileResult>>('/api/ops/logs/laravel', { params: params(query) })

/** GET /api/ops/logs/laravel/download —— 按当前查询条件下载 Laravel 日志（Blob 响应） */
export const downloadLaravelLogs = (query: LogQuery) =>
    request.get<Blob>('/api/ops/logs/laravel/download', {
        params: params(query),
        responseType: 'blob',
    })

/** GET /api/ops/logs/octane —— 读取 Octane 日志；query 控制读取范围与过滤条件 */
export const getOctaneLogs = (query: LogQuery) =>
    request.get<ApiResponse<LogFileResult>>('/api/ops/logs/octane', { params: params(query) })

/** GET /api/ops/logs/octane/download —— 按当前查询条件下载 Octane 日志（Blob 响应） */
export const downloadOctaneLogs = (query: LogQuery) =>
    request.get<Blob>('/api/ops/logs/octane/download', {
        params: params(query),
        responseType: 'blob',
    })

/** GET /api/ops/logs/system —— 读取系统日志；query.source 指定具体来源 */
export const getSystemLogs = (query: LogQuery) =>
    request.get<ApiResponse<LogFileResult>>('/api/ops/logs/system', { params: params(query) })

/** GET /api/ops/logs/system/download —— 按当前查询条件下载系统日志（Blob 响应） */
export const downloadSystemLogs = (query: LogQuery) =>
    request.get<Blob>('/api/ops/logs/system/download', {
        params: params(query),
        responseType: 'blob',
    })

/** GET /api/ops/logs/redis —— 读取 Redis 慢日志；query 控制分页与过滤条件 */
export const getRedisSlowLogs = (query: LogQuery) =>
    request.get<ApiResponse<RedisSlowLogResult>>('/api/ops/logs/redis', { params: params(query) })

/** GET /api/ops/logs/redis/download —— 按当前查询条件下载 Redis 慢日志（Blob 响应） */
export const downloadRedisSlowLogs = (query: LogQuery) =>
    request.get<Blob>('/api/ops/logs/redis/download', {
        params: params(query),
        responseType: 'blob',
    })

/** GET /api/ops/logs/docker —— 读取 Docker 容器日志；query.container 指定容器 */
export const getDockerLogs = (query: LogQuery) =>
    request.get<ApiResponse<LogFileResult>>('/api/ops/logs/docker', { params: params(query) })

/** GET /api/ops/logs/docker/download —— 按当前查询条件下载 Docker 日志（Blob 响应） */
export const downloadDockerLogs = (query: LogQuery) =>
    request.get<Blob>('/api/ops/logs/docker/download', {
        params: params(query),
        responseType: 'blob',
    })

/** GET /api/ops/logs/system/sources —— 获取可选的系统日志来源列表 */
export const getSystemLogSources = () =>
    request.get<ApiResponse<SystemLogSourcesResult>>('/api/ops/logs/system/sources')
