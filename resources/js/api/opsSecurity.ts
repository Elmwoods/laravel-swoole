import request from '@/utils/request'

interface ApiResponse<T> {
    code: number
    message: string
    data: T
    timestamp: number
}

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

export const getSecurityOverview = () =>
    request.get<ApiResponse<SecurityOverview>>('/api/ops/security/overview')
