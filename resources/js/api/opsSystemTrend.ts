import request from '@/utils/request'

export interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

export interface SystemMetricBucket {
    date: string
    cpu_load: number
    load1: number
    memory_used_percent: number
    swap_used_percent: number
}

export interface SystemMetricsTrendResult {
    days: number
    buckets: SystemMetricBucket[]
}

export const getSystemMetricsTrend = (days = 14) =>
    request.get<ApiResponse<SystemMetricsTrendResult>>('/api/ops/system/metrics-trend', { params: { days } })
