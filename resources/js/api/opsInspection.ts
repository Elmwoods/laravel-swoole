// 系统巡检 API：触发巡检、查询巡检摘要/历史/详情及趋势
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

/** 巡检结论状态：pass 通过 / warn 警告 / fail 失败 */
export type InspectionStatus = 'pass' | 'warn' | 'fail'
/** 巡检类型：light 轻量巡检 / full 完整巡检 */
export type InspectionType = 'light' | 'full'
/** 巡检触发方式：manual 手动 / schedule 定时任务 */
export type InspectionTrigger = 'manual' | 'schedule'

/**
 * 巡检结论计数
 * 通过/警告/失败三类检查项的数量统计
 */
export interface InspectionSummaryCounts {
    pass: number
    warn: number
    fail: number
}

/**
 * 单个巡检检查项
 * group 分组、name 名称、status 结论、message 说明、hint 修复建议；索引签名允许携带额外字段
 */
export interface InspectionCheckItem {
    group: string
    name: string
    status: InspectionStatus
    message: string
    hint: string
    [key: string]: unknown
}

/**
 * 巡检记录摘要
 * 一次巡检的元信息：类型、触发方式、整体结论、计数、耗时、起止时间及操作人
 */
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

/**
 * 巡检记录详情
 * 在摘要基础上附带每一项检查的明细列表
 */
export interface InspectionRecordDetail extends InspectionRecordSummary {
    checks: InspectionCheckItem[]
}

/**
 * 巡检概览结果
 * latest：最近一次巡检摘要（可能为空）；summary：其结论计数
 */
export interface InspectionSummaryResult {
    latest: InspectionRecordSummary | null
    summary: InspectionSummaryCounts
}

/**
 * 巡检历史分页结果
 * items：巡检记录摘要列表；pagination：分页信息
 */
export interface InspectionHistoryResult {
    items: InspectionRecordSummary[]
    pagination: {
        current_page: number
        per_page: number
        total: number
        last_page: number
    }
}

/**
 * 巡检趋势单日桶
 * 某一天的通过/警告/失败计数、总数与平均耗时
 */
export interface InspectionTrendBucket {
    date: string
    pass: number
    warn: number
    fail: number
    total: number
    avg_duration_ms: number
}

/**
 * 巡检趋势结果
 * days：统计天数窗口；buckets：按天聚合的趋势桶数组
 */
export interface InspectionTrendResult {
    days: number
    buckets: InspectionTrendBucket[]
}

/** GET /api/ops/inspections/summary —— 获取巡检概览（最近一次巡检及其结论计数） */
export const getInspectionSummary = () =>
    request.get<ApiResponse<InspectionSummaryResult>>('/api/ops/inspections/summary')

/** GET /api/ops/inspections/history —— 分页查询巡检历史；params 传递分页与筛选条件 */
export const getInspectionHistory = (params = {}) =>
    request.get<ApiResponse<InspectionHistoryResult>>('/api/ops/inspections/history', { params })

/** GET /api/ops/inspections/history/{id} —— 获取指定巡检记录的检查项明细 */
export const getInspectionDetail = (id: number) =>
    request.get<ApiResponse<InspectionRecordDetail>>(`/api/ops/inspections/history/${id}`)

/** POST /api/ops/inspections/run —— 立即触发一次巡检并返回其详情 */
export const runInspection = () =>
    request.post<ApiResponse<InspectionRecordDetail>>('/api/ops/inspections/run')

/** GET /api/ops/inspections/trend —— 获取近 days 天的巡检趋势（默认 14 天） */
export const getInspectionTrend = (days = 14) =>
    request.get<ApiResponse<InspectionTrendResult>>('/api/ops/inspections/trend', { params: { days } })
