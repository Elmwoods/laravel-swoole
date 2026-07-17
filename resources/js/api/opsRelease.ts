import request from '@/utils/request'

export interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

export type ReleaseCheckStatus = 'pass' | 'warn' | 'fail'

export interface ReleaseCheckSummary {
    pass: number
    warn: number
    fail: number
}

export interface ReleaseCheckItem {
    group: string
    name: string
    status: ReleaseCheckStatus
    message: string
    hint: string
    [key: string]: unknown
}

export interface ReleaseCheckRecordSummary {
    id: number
    admin_user_id: number | null
    admin_email: string | null
    status: ReleaseCheckStatus
    summary: ReleaseCheckSummary
    duration_ms: number
    started_at: string | null
    finished_at: string | null
    error_message: string | null
    created_at: string | null
}

export interface ReleaseCheckRecordDetail extends ReleaseCheckRecordSummary {
    checks: ReleaseCheckItem[]
}

export interface ReleaseCheckOverview {
    permission: 'ops.release.view'
    commands: {
        default: string
        json: string
        strict: string
    }
    latest: ReleaseCheckRecordSummary | null
}

export interface ReleaseCheckHistoryResult {
    items: ReleaseCheckRecordSummary[]
    pagination: {
        current_page: number
        per_page: number
        total: number
        last_page: number
    }
}

export const getReleaseCheckOverview = () =>
    request.get<ApiResponse<ReleaseCheckOverview>>('/api/ops/release-check/overview')

export const runReleaseCheck = () =>
    request.post<ApiResponse<ReleaseCheckRecordDetail>>('/api/ops/release-check/run')

export const getReleaseCheckHistory = (params = {}) =>
    request.get<ApiResponse<ReleaseCheckHistoryResult>>('/api/ops/release-check/history', { params })

export const getReleaseCheckDetail = (id: number) =>
    request.get<ApiResponse<ReleaseCheckRecordDetail>>(`/api/ops/release-check/history/${id}`)
