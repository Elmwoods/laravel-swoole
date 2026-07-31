// 管理后台 IP 准入 API：IP 黑白名单规则的增删改查、准入模式与自动封禁设置
import request from '@/utils/request'

/**
 * 后端统一响应结构
 * code：业务状态码；message：提示文案；data：真正的业务数据载荷
 */
interface ApiResponse<T> {
    code: number
    message: string
    data: T
}

/** IP 规则类型：allow 放行 / deny 拒绝 */
export type IpRuleType = 'allow' | 'deny'
/** 准入模式：blocklist 黑名单（默认放行，命中即拒） / allowlist 白名单（默认拒绝，命中才放行） */
export type IpAccessMode = 'blocklist' | 'allowlist'

/**
 * 单条 IP 准入规则
 * cidr 为 IP 段；source 区分 manual 手动添加与 auto 自动封禁生成；expires_at 为规则到期时间
 */
export interface AdminIpRule {
    id: number
    type: IpRuleType
    cidr: string
    label: string | null
    is_active: boolean
    source: 'manual' | 'auto'
    expires_at: string | null
    created_at: string | null
}

/**
 * IP 准入全局设置
 * 是否启用准入控制、采用黑名单还是白名单模式、是否开启自动封禁
 */
export interface IpAccessSettings {
    ip_access_enabled: boolean
    ip_access_mode: IpAccessMode
    auto_ban_enabled: boolean
}

/**
 * 自动封禁策略摘要
 * threshold：触发封禁的失败次数阈值；window_minutes：统计窗口（分钟）；ban_minutes：封禁时长（分钟）
 */
export interface AutoBanSummary {
    threshold: number
    window_minutes: number
    ban_minutes: number
}

/**
 * IP 准入总览
 * 聚合当前设置、规则列表、发起请求的客户端 IP 以及自动封禁策略，供管理页面一次性渲染
 */
export interface IpAccessOverview {
    settings: IpAccessSettings
    rules: AdminIpRule[]
    client_ip: string
    auto_ban: AutoBanSummary
}

/** GET /api/admin/ip-rules —— 获取 IP 准入总览（设置 + 规则 + 客户端 IP + 自动封禁策略） */
export const getIpAccess = () =>
    request.get<ApiResponse<IpAccessOverview>>('/api/admin/ip-rules')

/** POST /api/admin/ip-rules —— 新建一条 IP 规则；payload 含类型、CIDR 段与可选备注 */
export const createIpRule = (payload: { type: IpRuleType; cidr: string; label?: string | null }) =>
    request.post<ApiResponse<{ rule: AdminIpRule }>>('/api/admin/ip-rules', payload)

/** PATCH /api/admin/ip-rules/{id} —— 启用/停用指定规则（isActive 控制 is_active 字段） */
export const toggleIpRule = (id: number, isActive: boolean) =>
    request.patch<ApiResponse<{ updated: boolean }>>(`/api/admin/ip-rules/${id}`, { is_active: isActive })

/** DELETE /api/admin/ip-rules/{id} —— 删除指定 IP 规则 */
export const deleteIpRule = (id: number) =>
    request.delete<ApiResponse<{ deleted: boolean }>>(`/api/admin/ip-rules/${id}`)

/** PUT /api/admin/ip-access/settings —— 更新 IP 准入全局设置（开关、模式、自动封禁开关） */
export const updateIpAccessSettings = (payload: IpAccessSettings) =>
    request.put<ApiResponse<{ settings: IpAccessSettings }>>('/api/admin/ip-access/settings', payload)
