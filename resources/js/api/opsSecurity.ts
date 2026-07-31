// 安全总览 API：将登录风险、失败登录、会话、2FA、IP 规则、通道健康等安全指标聚合为一屏概览
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

/**
 * 安全总览数据
 * 聚合安全大盘所需的各维度指标：
 * - login_risk：登录风险（窗口内总登录数、新 IP/新设备登录数）
 * - failed_logins：失败登录（窗口小时数、总数、Top IP）
 * - sessions：活跃会话数与去重管理员数
 * - two_factor：2FA 覆盖情况（活跃管理员、已启用数、覆盖率）
 * - trusted_devices：受信任设备数
 * - security_alerts：未关闭安全告警（按来源、按级别拆分）
 * - ip_rules：生效中的放行/拒绝/自动封禁规则数
 * - channel_health：通知通道健康统计
 * - failed_login_trend：失败登录按日趋势
 * - recent_events：最近安全事件列表
 */
export interface SecurityOverview {
    generated_at: string
    login_risk: { window_days: number; total_logins: number; new_ip_logins: number; new_device_logins: number }
    failed_logins: { window_hours: number; total: number; top_ips: Array<{ ip: string; total: number }> }
    sessions: { active: number; distinct_admins: number }
    two_factor: { active_admins: number; enabled: number; coverage_percent: number }
    trusted_devices: { active: number }
    security_alerts: {
        open_total: number
        by_source: Array<{ source: string; total: number }>
        by_severity: { critical: number; warning: number; info: number }
    }
    ip_rules: { allow_active: number; deny_active: number; auto_ban_active: number }
    channel_health: { healthy: number; failing: number; unknown: number; total: number }
    failed_login_trend: Array<{ date: string; total: number }>
    recent_events: Array<{ source: string; severity: string; title: string; status: string; at: string | null }>
}

/** GET /api/ops/security/overview —— 获取安全大盘总览（各安全维度的聚合指标） */
export const getSecurityOverview = () =>
    request.get<ApiResponse<SecurityOverview>>('/api/ops/security/overview')
