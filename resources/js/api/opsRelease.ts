// 发布检查（Release Check）API：上线前的准备度检查，含概览、执行、历史与详情
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

/** 发布检查结论状态：pass 通过 / warn 警告 / fail 失败 */
export type ReleaseCheckStatus = 'pass' | 'warn' | 'fail'

/**
 * 发布检查结论计数
 * 通过/警告/失败三类检查项的数量统计
 */
export interface ReleaseCheckSummary {
    pass: number
    warn: number
    fail: number
}

/**
 * 单个发布检查项
 * group 分组、name 名称、status 结论、message 说明、hint 修复建议；索引签名允许携带额外字段
 */
export interface ReleaseCheckItem {
    group: string
    name: string
    status: ReleaseCheckStatus
    message: string
    hint: string
    [key: string]: unknown
}

/**
 * 发布检查记录摘要
 * 一次发布检查的元信息：操作人、整体结论、计数、耗时、起止时间及错误信息
 */
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

/**
 * 发布检查记录详情
 * 在摘要基础上附带每一项检查的明细列表
 */
export interface ReleaseCheckRecordDetail extends ReleaseCheckRecordSummary {
    checks: ReleaseCheckItem[]
}

/**
 * 发布检查概览
 * permission：所需权限标识；commands：对应的 CLI 命令（默认/JSON 输出/严格模式）；latest：最近一次记录
 */
export interface ReleaseCheckOverview {
    permission: 'ops.release.view'
    commands: {
        default: string
        json: string
        strict: string
    }
    latest: ReleaseCheckRecordSummary | null
}

/**
 * 发布检查历史分页结果
 * items：记录摘要列表；pagination：分页信息
 */
export interface ReleaseCheckHistoryResult {
    items: ReleaseCheckRecordSummary[]
    pagination: {
        current_page: number
        per_page: number
        total: number
        last_page: number
    }
}

/** GET /api/ops/release-check/overview —— 获取发布检查概览（权限、CLI 命令、最近一次记录） */
export const getReleaseCheckOverview = () =>
    request.get<ApiResponse<ReleaseCheckOverview>>('/api/ops/release-check/overview')

/** POST /api/ops/release-check/run —— 立即执行一次发布检查并返回详情 */
export const runReleaseCheck = () =>
    request.post<ApiResponse<ReleaseCheckRecordDetail>>('/api/ops/release-check/run')

/** GET /api/ops/release-check/history —— 分页查询发布检查历史；params 传递分页与筛选条件 */
export const getReleaseCheckHistory = (params = {}) =>
    request.get<ApiResponse<ReleaseCheckHistoryResult>>('/api/ops/release-check/history', { params })

/** GET /api/ops/release-check/history/{id} —— 获取指定发布检查记录的检查项明细 */
export const getReleaseCheckDetail = (id: number) =>
    request.get<ApiResponse<ReleaseCheckRecordDetail>>(`/api/ops/release-check/history/${id}`)
