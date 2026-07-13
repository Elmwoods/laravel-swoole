import request from '@/utils/request'

export interface DailyCoinRecord {
    date: string
    status: 'pending' | 'claimed' | 'failed' | 'skipped'
    coins: number | null
    note: string | null
    updated_at: string | null
}

export interface DailyCoinSummary {
    today: DailyCoinRecord
    reminder: {
        due: boolean
        sent_today: boolean
    }
    history: DailyCoinRecord[]
    safety: {
        private_token_automation_allowed: boolean
    }
    checked_at: string
}

interface ApiResponse<T> {
    code: number
    message: string
    data: T
}

export const getDailyCoinSummary = () =>
    request.get<ApiResponse<DailyCoinSummary>>('/api/ops/coin-assistant/summary')

export const markDailyCoinReminder = () =>
    request.post<ApiResponse<DailyCoinSummary>>('/api/ops/coin-assistant/reminder')

export const confirmDailyCoin = (payload: {
    status: 'claimed' | 'failed' | 'skipped'
    coins?: number | null
    note?: string | null
}) => request.post<ApiResponse<DailyCoinSummary>>('/api/ops/coin-assistant/confirm', payload)

export const requestDailyCoinAutomation = (payload: {
    mode: 'manual_reminder' | 'private_token'
    token?: string
}) => request.post<ApiResponse<{ mode: string; accepted: boolean }>>('/api/ops/coin-assistant/automation-request', payload)
