// 告警静默（Silence）API：在指定时间段内抑制符合条件的告警通知，支持单次/每日/每周重复
import request from '@/utils/request'

/**
 * 后端统一响应结构
 * code：业务状态码；message：提示文案；data：数据载荷；timestamp：服务端时间戳
 */
interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

/** 静默重复方式：once 单次 / daily 每日 / weekly 每周 */
export type SilenceRecurrence = 'once' | 'daily' | 'weekly'

/**
 * 单条告警静默规则
 * 在 starts_at~ends_at 生效窗口内，对匹配 sources（来源）与 severities（级别）的告警抑制通知；
 * 重复型静默还用 days_of_week（周几）与 start_time/end_time（每日时段）界定生效时间
 */
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

/**
 * 静默总览
 * items：全部静默规则；active：当前时刻正在生效的静默规则子集
 */
export interface AlertSilenceOverview {
    items: AlertSilence[]
    active: AlertSilence[]
}

/** GET /api/ops/alerts/silences —— 获取静默总览（全部规则 + 当前生效规则） */
export const getAlertSilences = () =>
    request.get<ApiResponse<AlertSilenceOverview>>('/api/ops/alerts/silences')

/**
 * POST /api/ops/alerts/silences —— 新建一条静默规则
 * payload：生效窗口 starts_at/ends_at、可选重复策略与匹配的来源/级别范围
 */
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

/** PATCH /api/ops/alerts/silences/{id} —— 启用/停用指定静默规则 */
export const toggleAlertSilence = (id: number, isActive: boolean) =>
    request.patch<ApiResponse<{ updated: boolean }>>(`/api/ops/alerts/silences/${id}`, { is_active: isActive })

/** DELETE /api/ops/alerts/silences/{id} —— 删除指定静默规则 */
export const deleteAlertSilence = (id: number) =>
    request.delete<ApiResponse<{ deleted: boolean }>>(`/api/ops/alerts/silences/${id}`)
