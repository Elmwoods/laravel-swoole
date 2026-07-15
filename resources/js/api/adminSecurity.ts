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

interface AdminPasswordKey {
    key_id: string
    algorithm: 'RSA-OAEP-SHA1'
    public_key: string
}

interface EncryptedPasswordPayload {
    password_encrypted: string
    password_key_id: string
}

let cachedPasswordKey: AdminPasswordKey | null = null

export const getAdminPasswordKey = async () => {
    if (cachedPasswordKey) return cachedPasswordKey

    const res = await request.get<ApiResponse<AdminPasswordKey>>('/api/admin/auth/password-key')
    cachedPasswordKey = res.data.data

    return cachedPasswordKey
}

const pemToArrayBuffer = (pem: string) => {
    const base64 = pem
        .replace(/-----BEGIN PUBLIC KEY-----/g, '')
        .replace(/-----END PUBLIC KEY-----/g, '')
        .replace(/\s/g, '')
    const binary = atob(base64)
    const bytes = new Uint8Array(binary.length)

    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i)
    }

    return bytes.buffer
}

const arrayBufferToBase64 = (buffer: ArrayBuffer) => {
    const bytes = new Uint8Array(buffer)
    let binary = ''

    bytes.forEach(byte => {
        binary += String.fromCharCode(byte)
    })

    return btoa(binary)
}

const encryptAdminPassword = async (password: string): Promise<EncryptedPasswordPayload> => {
    if (!window.crypto?.subtle) {
        throw new Error('当前浏览器不支持安全密码加密，请升级浏览器后重试')
    }

    const key = await getAdminPasswordKey()
    const cryptoKey = await window.crypto.subtle.importKey(
        'spki',
        pemToArrayBuffer(key.public_key),
        { name: 'RSA-OAEP', hash: 'SHA-1' },
        false,
        ['encrypt'],
    )
    const encrypted = await window.crypto.subtle.encrypt(
        { name: 'RSA-OAEP' },
        cryptoKey,
        new TextEncoder().encode(password),
    )

    return {
        password_encrypted: arrayBufferToBase64(encrypted),
        password_key_id: key.key_id,
    }
}

export const adminLogin = async (payload: { email: string; password: string }) => {
    const encryptedPassword = await encryptAdminPassword(payload.password)

    return request.post<ApiResponse<AdminProfile>>('/api/admin/auth/login', {
        email: payload.email,
        ...encryptedPassword,
    })
}

export const adminLogout = () =>
    request.post<ApiResponse<{ logged_out: boolean }>>('/api/admin/auth/logout')

export const getAdminProfile = () =>
    request.get<ApiResponse<AdminProfile>>('/api/admin/auth/me')

export const getAdminUsers = (params = {}) =>
    request.get<ApiResponse<{ items: AdminUser[]; pagination: any }>>('/api/admin/users', { params })

export const createAdminUser = async (payload: any) => {
    const { password, ...rest } = payload
    const encryptedPassword = await encryptAdminPassword(password)

    return request.post<ApiResponse<AdminUser>>('/api/admin/users', {
        ...rest,
        ...encryptedPassword,
    })
}

export const updateAdminUser = (id: number, payload: any) =>
    request.put<ApiResponse<AdminUser>>(`/api/admin/users/${id}`, payload)

export const resetAdminPassword = async (id: number, payload: any) => {
    const password = await encryptAdminPassword(payload.password)
    const confirmation = await encryptAdminPassword(payload.password_confirmation)

    return request.post<ApiResponse<AdminUser>>(`/api/admin/users/${id}/reset-password`, {
        password_encrypted: password.password_encrypted,
        password_confirmation_encrypted: confirmation.password_encrypted,
        password_key_id: password.password_key_id,
    })
}

export const getAdminRoles = () =>
    request.get<ApiResponse<{ roles: AdminRole[]; permissions: AdminPermission[] }>>('/api/admin/roles')

export const createAdminRole = (payload: any) =>
    request.post<ApiResponse<AdminRole>>('/api/admin/roles', payload)

export const updateAdminRole = (id: number, payload: any) =>
    request.put<ApiResponse<AdminRole>>(`/api/admin/roles/${id}`, payload)

export const getAdminAuditLogs = (params = {}) =>
    request.get<ApiResponse<{ items: AdminAuditLog[]; pagination: any }>>('/api/admin/audit-logs', { params })
