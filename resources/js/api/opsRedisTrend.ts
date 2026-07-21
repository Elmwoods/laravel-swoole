import request from '@/utils/request'

export interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

export interface RedisMetricBucket {
    date: string
    ops: number
    clients: number
    memory_mb: number
    hit_rate: number
}

export interface RedisMetricsTrendResult {
    days: number
    buckets: RedisMetricBucket[]
}

export const getRedisMetricsTrend = (days = 14) =>
    request.get<ApiResponse<RedisMetricsTrendResult>>('/api/ops/redis-metrics/trend', { params: { days } })
