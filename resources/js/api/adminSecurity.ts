import request from '@/utils/request'

export interface AdminUser {
    id: number
    name: string
    email: string
    is_active: boolean
    roles: AdminRole[]
    last_login_at?: string | null
}

export interface AdminRole {
    id: number
    name: string
    slug: string
    description?: string | null
    is_active: boolean
    is_system: boolean
    permissions: AdminPermission[]
}

export interface AdminPermission {
    id: number
    name: string
    slug: string
    group: string
    description?: string | null
}

export interface AdminProfile {
    admin: AdminUser
    roles: AdminRole[]
    permissions: string[]
}

export interface AdminAuditLog {
    id: number
    admin_email: string | null
    admin_name: string | null
    module: string
    action: string
    result: 'success' | 'failure'
    status_code: number | null
    payload: Record<string, unknown>
    ip_address: string | null
    message: string | null
    created_at: string
}

interface ApiResponse<T> {
    code: number
    message: string
    data: T
}

export const adminLogin = (payload: { email: string; password: string }) =>
    request.post<ApiResponse<AdminProfile>>('/api/admin/auth/login', payload)

export const adminLogout = () =>
    request.post<ApiResponse<{ logged_out: boolean }>>('/api/admin/auth/logout')

export const getAdminProfile = () =>
    request.get<ApiResponse<AdminProfile>>('/api/admin/auth/me')

export const getAdminUsers = (params = {}) =>
    request.get<ApiResponse<{ items: AdminUser[]; pagination: any }>>('/api/admin/users', { params })

export const createAdminUser = (payload: any) =>
    request.post<ApiResponse<AdminUser>>('/api/admin/users', payload)

export const updateAdminUser = (id: number, payload: any) =>
    request.put<ApiResponse<AdminUser>>(`/api/admin/users/${id}`, payload)

export const resetAdminPassword = (id: number, payload: any) =>
    request.post<ApiResponse<AdminUser>>(`/api/admin/users/${id}/reset-password`, payload)

export const getAdminRoles = () =>
    request.get<ApiResponse<{ roles: AdminRole[]; permissions: AdminPermission[] }>>('/api/admin/roles')

export const createAdminRole = (payload: any) =>
    request.post<ApiResponse<AdminRole>>('/api/admin/roles', payload)

export const updateAdminRole = (id: number, payload: any) =>
    request.put<ApiResponse<AdminRole>>(`/api/admin/roles/${id}`, payload)

export const getAdminAuditLogs = (params = {}) =>
    request.get<ApiResponse<{ items: AdminAuditLog[]; pagination: any }>>('/api/admin/audit-logs', { params })
