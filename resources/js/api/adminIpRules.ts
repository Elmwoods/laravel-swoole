import request from '@/utils/request'

interface ApiResponse<T> {
    code: number
    message: string
    data: T
}

export type IpRuleType = 'allow' | 'deny'
export type IpAccessMode = 'blocklist' | 'allowlist'

export interface AdminIpRule {
    id: number
    type: IpRuleType
    cidr: string
    label: string | null
    is_active: boolean
    created_at: string | null
}

export interface IpAccessSettings {
    ip_access_enabled: boolean
    ip_access_mode: IpAccessMode
}

export interface IpAccessOverview {
    settings: IpAccessSettings
    rules: AdminIpRule[]
    client_ip: string
}

export const getIpAccess = () =>
    request.get<ApiResponse<IpAccessOverview>>('/api/admin/ip-rules')

export const createIpRule = (payload: { type: IpRuleType; cidr: string; label?: string | null }) =>
    request.post<ApiResponse<{ rule: AdminIpRule }>>('/api/admin/ip-rules', payload)

export const toggleIpRule = (id: number, isActive: boolean) =>
    request.patch<ApiResponse<{ updated: boolean }>>(`/api/admin/ip-rules/${id}`, { is_active: isActive })

export const deleteIpRule = (id: number) =>
    request.delete<ApiResponse<{ deleted: boolean }>>(`/api/admin/ip-rules/${id}`)

export const updateIpAccessSettings = (payload: IpAccessSettings) =>
    request.put<ApiResponse<{ settings: IpAccessSettings }>>('/api/admin/ip-access/settings', payload)
