import request from '@/utils/request'

export interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

export type InspectionStatus = 'pass' | 'warn' | 'fail'
export type InspectionType = 'light' | 'full'
export type InspectionTrigger = 'manual' | 'schedule'

export interface InspectionSummaryCounts {
    pass: number
    warn: number
    fail: number
}

export interface InspectionCheckItem {
    group: string
    name: string
    status: InspectionStatus
    message: string
    hint: string
    [key: string]: unknown
}

export interface InspectionRecordSummary {
    id: number
    type: InspectionType
    trigger: InspectionTrigger
    status: InspectionStatus
    summary: InspectionSummaryCounts
    duration_ms: number
    started_at: string | null
    finished_at: string | null
    failure_message: string | null
    admin_user_id: number | null
    admin_email: string | null
    created_at: string | null
}

export interface InspectionRecordDetail extends InspectionRecordSummary {
    checks: InspectionCheckItem[]
}

export interface InspectionSummaryResult {
    latest: InspectionRecordSummary | null
    summary: InspectionSummaryCounts
}

export interface InspectionHistoryResult {
    items: InspectionRecordSummary[]
    pagination: {
        current_page: number
        per_page: number
        total: number
        last_page: number
    }
}

export interface InspectionTrendBucket {
    date: string
    pass: number
    warn: number
    fail: number
    total: number
    avg_duration_ms: number
}

export interface InspectionTrendResult {
    days: number
    buckets: InspectionTrendBucket[]
}

export const getInspectionSummary = () =>
    request.get<ApiResponse<InspectionSummaryResult>>('/api/ops/inspections/summary')

export const getInspectionHistory = (params = {}) =>
    request.get<ApiResponse<InspectionHistoryResult>>('/api/ops/inspections/history', { params })

export const getInspectionDetail = (id: number) =>
    request.get<ApiResponse<InspectionRecordDetail>>(`/api/ops/inspections/history/${id}`)

export const runInspection = () =>
    request.post<ApiResponse<InspectionRecordDetail>>('/api/ops/inspections/run')

export const getInspectionTrend = (days = 14) =>
    request.get<ApiResponse<InspectionTrendResult>>('/api/ops/inspections/trend', { params: { days } })
