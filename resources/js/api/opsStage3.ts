// Ops Center 第三阶段 API
// 说明：日志中心统一从这里请求，页面只负责展示和交互。
import request from '@/utils/request'

export interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

export interface LogFileResult {
    source: string
    path: string | null
    exists: boolean
    readable: boolean
    message?: string
    lines: string[]
    entries?: LogEntry[]
    facets?: LogFacets
    pagination?: LogPagination
    count: number
    entry_count?: number
    checked_at: string
}

export interface LogPagination {
    current_page: number
    per_page: number
    total: number
    last_page: number
    has_more: boolean
}

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

export interface RedisSlowLogEntry {
    id: number
    occurred_at: string
    duration_ms: number
    command: string
    client: string
    client_name: string
}

export interface RedisSlowLogResult {
    source: string
    available: boolean
    message?: string
    entries: RedisSlowLogEntry[]
    count: number
    checked_at: string
}

export interface SystemLogSource {
    key: string
    path: string
    exists: boolean
    readable: boolean
}

export interface SystemLogSourcesResult {
    sources: SystemLogSource[]
}

export interface LogQuery {
    lines: number
    page?: number
    per_page?: number
    keyword?: string
    level?: string
    source?: string
    container?: string
}

const params = (query: LogQuery) => ({
    lines: query.lines,
    page: query.page || undefined,
    per_page: query.per_page || undefined,
    keyword: query.keyword || undefined,
    level: query.level || undefined,
    source: query.source || undefined,
    container: query.container || undefined,
})

export const getLaravelLogs = (query: LogQuery) =>
    request.get<ApiResponse<LogFileResult>>('/api/ops/logs/laravel', { params: params(query) })

export const getOctaneLogs = (query: LogQuery) =>
    request.get<ApiResponse<LogFileResult>>('/api/ops/logs/octane', { params: params(query) })

export const getSystemLogs = (query: LogQuery) =>
    request.get<ApiResponse<LogFileResult>>('/api/ops/logs/system', { params: params(query) })

export const getRedisSlowLogs = (query: LogQuery) =>
    request.get<ApiResponse<RedisSlowLogResult>>('/api/ops/logs/redis', { params: params(query) })

export const getSystemLogSources = () =>
    request.get<ApiResponse<SystemLogSourcesResult>>('/api/ops/logs/system/sources')
