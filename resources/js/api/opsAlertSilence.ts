import request from '@/utils/request'

interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

export type SilenceRecurrence = 'once' | 'daily' | 'weekly'

export interface AlertSilence {
    id: number
    label: string | null
    starts_at: string | null
    ends_at: string | null
    recurrence: SilenceRecurrence
    days_of_week: number[]
    start_time: string | null
    end_time: string | null
    sources: string[]
    severities: string[]
    is_active: boolean
    created_at: string | null
}

export interface AlertSilenceOverview {
    items: AlertSilence[]
    active: AlertSilence[]
}

export const getAlertSilences = () =>
    request.get<ApiResponse<AlertSilenceOverview>>('/api/ops/alerts/silences')

export const createAlertSilence = (payload: {
    label?: string | null
    starts_at: string
    ends_at: string
    recurrence?: SilenceRecurrence
    days_of_week?: number[]
    start_time?: string | null
    end_time?: string | null
    sources?: string[]
    severities?: string[]
}) => request.post<ApiResponse<{ silence: { id: number } }>>('/api/ops/alerts/silences', payload)

export const toggleAlertSilence = (id: number, isActive: boolean) =>
    request.patch<ApiResponse<{ updated: boolean }>>(`/api/ops/alerts/silences/${id}`, { is_active: isActive })

export const deleteAlertSilence = (id: number) =>
    request.delete<ApiResponse<{ deleted: boolean }>>(`/api/ops/alerts/silences/${id}`)
