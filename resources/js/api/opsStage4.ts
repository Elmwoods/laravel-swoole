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
    assigned_to: string | null
    assigned_at: string | null
    escalated_at: string | null
    timeline: AlertTimelineItem[]
    created_at: string | null
    updated_at: string | null
}

export interface AlertTimelineItem {
    id: number
    action: string
    actor: string | null
    from_status: string | null
    to_status: string | null
    note: string | null
    metadata: Record<string, unknown>
    created_at: string | null
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
    auto_resolved?: number
    summary: AlertSummary
    checked_at: string
}

export interface AlertNotificationTestResult {
    result: Record<string, {
        enabled: boolean
        sent: boolean
        status?: number
        reason?: string
    }>
    checked_at: string
}

export interface ChannelStatus {
    enabled: boolean
    configured: boolean
    missing: string[]
    health?: 'healthy' | 'failing' | 'unknown'
    consecutive_failures?: number
    last_checked_at?: string | null
    last_error?: string | null
}

export interface AlertNotificationStatus {
    checked_at: string
    settings?: AlertSettings
    // 其余键为各通道（telegram/mail/webhook/dingtalk/feishu …）的 ChannelStatus
    [channel: string]: ChannelStatus | AlertSettings | string | undefined
}

export interface AlertSettings {
    notification_repeat_minutes: number
    auto_resolve_enabled: boolean
    auto_resolve_grace_minutes: number
    escalation_enabled: boolean
    escalation_after_minutes: number
    severity_channels: Record<AlertSeverity, Record<string, boolean>>
    // 各通道总开关 <channel>_enabled
    [key: string]: number | boolean | Record<string, unknown>
}

export interface AlertEvaluationStatus {
    id: number
    trigger: string
    status: 'success' | 'failure'
    detected_count: number
    auto_resolved_count: number
    started_at: string | null
    finished_at: string | null
    duration_ms: number
    message: string | null
}

export interface AlertDemoScenarioResult {
    enabled: boolean
    created: number
    items: OpsAlert[]
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

export interface AlertRule {
    id: number
    key: string
    name: string
    source: string
    metric: string
    operator: string
    warning_threshold: number
    critical_threshold: number | null
    unit: string | null
    is_active: boolean
    description: string | null
    sort_order: number
    min: number
    max: number
    requires_critical: boolean
    updated_at: string | null
}

export interface AlertRuleListResult {
    items: AlertRule[]
}

export interface AlertRuleUpdatePayload {
    warning_threshold: number
    critical_threshold: number | null
    is_active: boolean
}

export const getAlertSummary = () =>
    request.get<ApiResponse<AlertSummary>>('/api/ops/alerts/summary')

export const getAlertNotificationStatus = () =>
    request.get<ApiResponse<AlertNotificationStatus>>('/api/ops/alerts/notification-status')

export const getAlertSettings = () =>
    request.get<ApiResponse<AlertSettings>>('/api/ops/alerts/settings')

export const updateAlertSettings = (payload: AlertSettings) =>
    request.put<ApiResponse<AlertSettings>>('/api/ops/alerts/settings', payload)

export const getLatestAlertEvaluation = () =>
    request.get<ApiResponse<AlertEvaluationStatus | null>>('/api/ops/alerts/evaluations/latest')

export interface AlertTrendBucket {
    date: string
    evaluations: number
    detected: number
    auto_resolved: number
    avg_duration_ms: number
}

export interface AlertTrendResult {
    days: number
    buckets: AlertTrendBucket[]
}

export const getAlertTrend = (days = 14) =>
    request.get<ApiResponse<AlertTrendResult>>('/api/ops/alerts/trend', { params: { days } })

export interface DurationStat {
    count: number
    avg_seconds: number
    max_seconds: number
}

export interface AlertSlaResult {
    days: number
    window_days: number
    generated_at: string
    mtta: DurationStat
    mttr: DurationStat
    by_source: Array<{ source: string; mtta_avg_seconds: number; mtta_count: number; mttr_avg_seconds: number; mttr_count: number }>
    by_severity: Record<'critical' | 'warning' | 'info', { mttr_avg_seconds: number; mttr_count: number }>
    trend: Array<{ date: string; mttr_avg_seconds: number; resolved_count: number }>
    open_aging: { under_1h: number; one_to_24h: number; over_24h: number }
    targets: {
        enabled: boolean
        ack_minutes: { critical: number; warning: number; info: number }
        resolve_minutes: { critical: number; warning: number; info: number }
    }
    open_breaches: number
}

export const getAlertSla = (days = 30) =>
    request.get<ApiResponse<AlertSlaResult>>('/api/ops/alerts/sla', { params: { days } })

export interface AlertPreset {
    id: number
    name: string
    filters: Record<string, string>
    created_at: string | null
}

export const getAlertPresets = () =>
    request.get<ApiResponse<{ items: AlertPreset[] }>>('/api/ops/alerts/presets')

export const saveAlertPreset = (payload: { name: string; filters: Record<string, unknown> }) =>
    request.post<ApiResponse<{ preset: AlertPreset }>>('/api/ops/alerts/presets', payload)

export const deleteAlertPreset = (id: number) =>
    request.delete<ApiResponse<{ deleted: boolean }>>(`/api/ops/alerts/presets/${id}`)

export const getAlertRules = () =>
    request.get<ApiResponse<AlertRuleListResult>>('/api/ops/alerts/rules')

export const updateAlertRule = (key: string, payload: AlertRuleUpdatePayload) =>
    request.put<ApiResponse<AlertRule>>(`/api/ops/alerts/rules/${encodeURIComponent(key)}`, payload)

export const toggleAlertRule = (key: string, is_active: boolean) =>
    request.post<ApiResponse<AlertRule>>(`/api/ops/alerts/rules/${encodeURIComponent(key)}/toggle`, { is_active })

export const getAlerts = (query: AlertQuery) =>
    request.get<ApiResponse<AlertListResult>>('/api/ops/alerts', { params: query })

export const evaluateAlerts = () =>
    request.post<ApiResponse<AlertEvaluateResult>>('/api/ops/alerts/evaluate')

export const testAlertNotification = (payload: { channels?: string[]; message?: string }) =>
    request.post<ApiResponse<AlertNotificationTestResult>>('/api/ops/alerts/test-notification', payload)

// 返回体是 AlertNotificationStatus 再附带一个 summary（探测统计）；summary 与通道索引签名冲突，故放宽为 any。
export const runAlertHealthCheck = () =>
    request.post<ApiResponse<any>>('/api/ops/alerts/health-check')

export const createAlertDemoScenarios = () =>
    request.post<ApiResponse<AlertDemoScenarioResult>>('/api/ops/alerts/demo-scenarios')

export const acknowledgeAlert = (id: number, payload: { acknowledged_by?: string; note?: string }) =>
    request.post<ApiResponse<OpsAlert>>(`/api/ops/alerts/${id}/acknowledge`, payload)

export const assignAlert = (id: number, payload: { assigned_to: string; note?: string }) =>
    request.post<ApiResponse<OpsAlert>>(`/api/ops/alerts/${id}/assign`, payload)

export const resolveAlert = (id: number, payload: { acknowledged_by?: string; note?: string }) =>
    request.post<ApiResponse<OpsAlert>>(`/api/ops/alerts/${id}/resolve`, payload)
