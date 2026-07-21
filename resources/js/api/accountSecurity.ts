import request from '@/utils/request'

interface ApiResponse<T> {
    code: number
    message: string
    data: T
}

export interface AdminLoginEvent {
    id: number
    ip_address: string | null
    user_agent: string | null
    trusted: boolean
    is_new_ip: boolean
    is_new_user_agent: boolean
    created_at: string | null
}

export interface AdminTrustedDevice {
    id: number
    label: string | null
    last_ip: string | null
    last_user_agent: string | null
    expires_at: string | null
    created_at: string | null
}

export const getLoginHistory = () =>
    request.get<ApiResponse<{ events: AdminLoginEvent[] }>>('/api/admin/auth/login-history')

export const getTrustedDevices = () =>
    request.get<ApiResponse<{ devices: AdminTrustedDevice[] }>>('/api/admin/auth/trusted-devices')

export const revokeTrustedDevice = (id: number) =>
    request.post<ApiResponse<{ revoked: boolean }>>(`/api/admin/auth/trusted-devices/${id}/revoke`)
