// 管理员账号安全 API：登录历史、活跃会话、受信任设备的查询与撤销
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

/**
 * 单条管理员登录事件
 * 记录一次登录的来源信息，用于识别是否为新 IP / 新设备等风险信号
 */
export interface AdminLoginEvent {
    id: number
    ip_address: string | null
    user_agent: string | null
    trusted: boolean
    is_new_ip: boolean
    is_new_user_agent: boolean
    created_at: string | null
}

/**
 * 受信任设备
 * 通过“记住此设备”登录后建立，在有效期内可跳过二次验证；expires_at 为到期时间
 */
export interface AdminTrustedDevice {
    id: number
    label: string | null
    last_ip: string | null
    last_user_agent: string | null
    expires_at: string | null
    created_at: string | null
}

/**
 * 当前活跃会话
 * current 标记该会话是否为“正在使用的这一个”，用于避免误撤销自身
 */
export interface AdminActiveSession {
    id: number
    label: string | null
    ip_address: string | null
    user_agent: string | null
    current: boolean
    last_activity_at: string | null
    created_at: string | null
}

/** GET /api/admin/auth/login-history —— 获取当前管理员的登录历史事件列表 */
export const getLoginHistory = () =>
    request.get<ApiResponse<{ events: AdminLoginEvent[] }>>('/api/admin/auth/login-history')

/** GET /api/admin/auth/sessions —— 获取当前管理员的全部活跃会话 */
export const getActiveSessions = () =>
    request.get<ApiResponse<{ sessions: AdminActiveSession[] }>>('/api/admin/auth/sessions')

/** POST /api/admin/auth/sessions/{id}/revoke —— 撤销指定 id 的单个会话（强制该会话下线） */
export const revokeSession = (id: number) =>
    request.post<ApiResponse<{ revoked: boolean }>>(`/api/admin/auth/sessions/${id}/revoke`)

/** POST /api/admin/auth/sessions/revoke-others —— 撤销除当前会话外的所有其他会话，返回撤销数量 */
export const revokeOtherSessions = () =>
    request.post<ApiResponse<{ revoked: number }>>('/api/admin/auth/sessions/revoke-others')

/** GET /api/admin/auth/trusted-devices —— 获取当前管理员的受信任设备列表 */
export const getTrustedDevices = () =>
    request.get<ApiResponse<{ devices: AdminTrustedDevice[] }>>('/api/admin/auth/trusted-devices')

/** POST /api/admin/auth/trusted-devices/{id}/revoke —— 撤销指定受信任设备，之后该设备需重新二次验证 */
export const revokeTrustedDevice = (id: number) =>
    request.post<ApiResponse<{ revoked: boolean }>>(`/api/admin/auth/trusted-devices/${id}/revoke`)
