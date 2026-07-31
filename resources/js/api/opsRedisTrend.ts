// Redis 指标趋势 API：按天返回 Redis 关键指标的历史曲线数据
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
 * Redis 指标单日桶
 * date：日期；ops 每秒操作数；clients 连接数；memory_mb 内存占用（MB）；hit_rate 命中率
 */
export interface RedisMetricBucket {
    date: string
    ops: number
    clients: number
    memory_mb: number
    hit_rate: number
}

/**
 * Redis 指标趋势结果
 * days：统计天数窗口；buckets：按天聚合的指标桶数组
 */
export interface RedisMetricsTrendResult {
    days: number
    buckets: RedisMetricBucket[]
}

/** GET /api/ops/redis-metrics/trend —— 获取近 days 天的 Redis 指标趋势（默认 14 天） */
export const getRedisMetricsTrend = (days = 14) =>
    request.get<ApiResponse<RedisMetricsTrendResult>>('/api/ops/redis-metrics/trend', { params: { days } })
