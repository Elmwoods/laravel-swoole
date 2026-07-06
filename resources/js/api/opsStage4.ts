// Ops Center 第四阶段 API
// 说明：告警中心通过 HTTP 分页读取详情，WebSocket 只接收轻量事件。
import request from '@/utils/request'

export interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

export type AlertSeverity = 'critical' | 'warning' | 'info'
export type AlertStatus = 'open' | 'acknowledged' | 'resolved'

export interface OpsAlert {
    id: number
    source: string
    severity: AlertSeverity
    title: string
    message: string
    context: Record<string, unknown>
    status: AlertStatus
    hit_count: number
    last_seen_at: string | null
    acknowledged_at: string | null
    acknowledged_by: string | null
    acknowledge_note: string | null
    created_at: string | null
    updated_at: string | null
}

export interface AlertSummary {
    open_total: number
    critical: number
    warning: number
    info: number
    sources: Array<{
        source: string
        total: number
    }>
    checked_at: string
}

export interface AlertPagination {
    current_page: number
    per_page: number
    total: number
    last_page: number
}

export interface AlertListResult {
    items: OpsAlert[]
    pagination: AlertPagination
}

export interface AlertQuery {
    status?: string
    severity?: string
    source?: string
    page?: number
    per_page?: number
}

export interface AlertEvaluateResult {
    detected: number
    alerts: OpsAlert[]
    summary: AlertSummary
    checked_at: string
}

export interface AlertRealtimePayload {
    id: number
    source: string
    severity: AlertSeverity
    title: string
    message: string
    status: AlertStatus
    hit_count: number
    last_seen_at: string | null
}

export const getAlertSummary = () =>
    request.get<ApiResponse<AlertSummary>>('/api/ops/alerts/summary')

export const getAlerts = (query: AlertQuery) =>
    request.get<ApiResponse<AlertListResult>>('/api/ops/alerts', { params: query })

export const evaluateAlerts = () =>
    request.post<ApiResponse<AlertEvaluateResult>>('/api/ops/alerts/evaluate')

export const acknowledgeAlert = (id: number, payload: { acknowledged_by?: string; note?: string }) =>
    request.post<ApiResponse<OpsAlert>>(`/api/ops/alerts/${id}/acknowledge`, payload)
