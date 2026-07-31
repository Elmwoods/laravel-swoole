// 管理后台安全与账号体系 API：登录/二次验证、用户与角色管理、审计日志，
// 并内置一套 RSA-OAEP 前端密码加密逻辑（密码密文提交，明文不出浏览器）。
import request from '@/utils/request'

/**
 * 管理员用户
 * 含账号基础信息、所属角色，以及可选的安全状态（是否启用 2FA、确认时间）与最近登录时间
 */
export interface AdminUser {
    id: number
    name: string
    email: string
    is_active: boolean
    roles: AdminRole[]
    security?: {
        two_factor_enabled: boolean
        two_factor_confirmed_at: string | null
    }
    last_login_at?: string | null
}

/**
 * 管理员角色
 * is_system 标记内置系统角色（通常不可删除）；permissions 为该角色绑定的权限集合
 */
export interface AdminRole {
    id: number
    name: string
    slug: string
    description?: string | null
    is_active: boolean
    is_system: boolean
    permissions: AdminPermission[]
}

/**
 * 单个权限项
 * slug 为权限唯一标识；group 用于在界面上按分组展示权限
 */
export interface AdminPermission {
    id: number
    name: string
    slug: string
    group: string
    description?: string | null
}

/**
 * 当前登录管理员的完整档案
 * 聚合账号本身、角色、扁平化权限 slug 列表以及安全摘要，供前端做权限判定与展示
 */
export interface AdminProfile {
    admin: AdminUser
    roles: AdminRole[]
    permissions: string[]
    security: AdminSecuritySummary
}

/**
 * 安全摘要
 * 记录上次登录的 IP/UA、当前请求的 IP/UA、会话版本号（用于全局失效）以及 2FA 状态
 */
export interface AdminSecuritySummary {
    last_login_at: string | null
    last_login_ip: string | null
    last_login_user_agent: string | null
    current_ip: string | null
    current_user_agent: string
    session_version: number
    two_factor_enabled: boolean
    two_factor_confirmed_at: string | null
}

/**
 * 二次验证初始化数据
 * secret：TOTP 密钥；otpauth_uri：可生成二维码供验证器 App 扫描的 otpauth 链接
 */
export interface AdminTwoFactorSetup {
    secret: string
    otpauth_uri: string
}

/**
 * 登录结果
 * 登录成功时携带档案字段；也可能返回“需要先设置 2FA”或“需要输入 2FA 验证码”两种待办状态
 */
export interface AdminLoginResult extends Partial<AdminProfile> {
    requires_two_factor_setup?: boolean
    requires_two_factor?: boolean
    setup?: AdminTwoFactorSetup
}

/**
 * 二次验证确认结果
 * 返回完整档案，并一次性下发恢复码（recovery_codes）供用户妥善保存
 */
export interface AdminTwoFactorConfirmResult {
    profile: AdminProfile
    recovery_codes: string[]
}

/**
 * 审计日志条目
 * 记录一次管理操作：所属模块/动作、成功或失败、目标对象、请求载荷与来源 IP 等
 */
export interface AdminAuditLog {
    id: number
    admin_user_id: number | null
    admin_email: string | null
    admin_name: string | null
    module: string
    action: string
    result: 'success' | 'failure'
    status_code: number | null
    target_type: string | null
    target_id: string | null
    payload: Record<string, unknown>
    ip_address: string | null
    message: string | null
    created_at: string
}

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
 * 后端下发的密码加密公钥
 * key_id：密钥标识，随密文一起回传后端以选择对应私钥；algorithm：约定算法；public_key：PEM 公钥
 */
interface AdminPasswordKey {
    key_id: string
    algorithm: 'RSA-OAEP-SHA1'
    public_key: string
}

/**
 * 加密后的密码载荷
 * password_encrypted：Base64 编码的密文；password_key_id：使用的公钥标识
 */
interface EncryptedPasswordPayload {
    password_encrypted: string
    password_key_id: string
}

// 缓存后端公钥，避免每次登录/改密都重新请求；失效时通过 clearAdminPasswordKey 清空
let cachedPasswordKey: AdminPasswordKey | null = null

/**
 * GET /api/admin/auth/password-key —— 获取密码加密公钥
 * 命中缓存则直接返回；否则请求后端并写入缓存
 */
export const getAdminPasswordKey = async () => {
    if (cachedPasswordKey) return cachedPasswordKey

    const res = await request.get<ApiResponse<AdminPasswordKey>>('/api/admin/auth/password-key')
    cachedPasswordKey = res.data.data

    return cachedPasswordKey
}

// 清空公钥缓存，下一次调用会强制重新拉取新公钥
const clearAdminPasswordKey = () => {
    cachedPasswordKey = null
}

/**
 * 判断错误是否为“公钥失效 / 密文无法解密”这类可重试错误
 * 仅当后端返回 422 且校验信息命中约定文案时才认为可重试
 */
const isRetryablePasswordKeyError = (error: any) => {
    if (error?.response?.status !== 422) return false

    const errors = error?.response?.data?.errors ?? {}
    const messages = Object.values(errors)
        .flat()
        .filter((message): message is string => typeof message === 'string')

    return messages.some(message =>
        message.includes('密码加密密钥已失效') ||
        message.includes('密码密文无法解密')
    )
}

/**
 * 包装一次涉及密码加密的请求：若因公钥失效失败，则清缓存、拉新公钥后自动重试一次
 * 用于登录、建号、重置密码等需要提交密文的场景
 */
const withPasswordKeyRetry = async <T>(operation: () => Promise<T>): Promise<T> => {
    try {
        return await operation()
    } catch (error) {
        if (!isRetryablePasswordKeyError(error)) {
            throw error
        }

        clearAdminPasswordKey()

        return operation()
    }
}

// 将 PEM 格式公钥去掉头尾标记与空白后 Base64 解码为二进制 ArrayBuffer，供 WebCrypto 导入
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

// 将加密结果 ArrayBuffer 转为 Base64 字符串，便于以 JSON 文本形式提交给后端
const arrayBufferToBase64 = (buffer: ArrayBuffer) => {
    const bytes = new Uint8Array(buffer)
    let binary = ''

    bytes.forEach(byte => {
        binary += String.fromCharCode(byte)
    })

    return btoa(binary)
}

/**
 * 使用后端公钥以 RSA-OAEP(SHA-1) 加密明文密码，返回可提交的密文载荷
 * 依赖浏览器 WebCrypto（window.crypto.subtle），不支持时抛出友好错误
 */
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

/** POST /api/admin/auth/login —— 管理员登录；密码在前端加密为密文后提交，遇公钥失效自动重试 */
export const adminLogin = async (payload: { email: string; password: string }) => {
    return withPasswordKeyRetry(async () => {
        const encryptedPassword = await encryptAdminPassword(payload.password)

        return request.post<ApiResponse<AdminLoginResult>>('/api/admin/auth/login', {
            email: payload.email,
            ...encryptedPassword,
        })
    })
}

/** POST /api/admin/auth/two-factor/confirm —— 首次启用 2FA 时提交验证码确认，成功后返回恢复码；trust_device 表示是否记住当前设备 */
export const confirmAdminTwoFactor = (payload: { code: string; trust_device?: boolean }) =>
    request.post<ApiResponse<AdminTwoFactorConfirmResult>>('/api/admin/auth/two-factor/confirm', payload)

/** POST /api/admin/auth/two-factor/challenge —— 登录时的 2FA 校验，支持 TOTP 验证码或恢复码二选一；trust_device 表示是否记住当前设备 */
export const challengeAdminTwoFactor = (payload: { code?: string; recovery_code?: string; trust_device?: boolean }) =>
    request.post<ApiResponse<AdminProfile>>('/api/admin/auth/two-factor/challenge', payload)

/** POST /api/admin/auth/logout —— 退出登录，注销当前会话 */
export const adminLogout = () =>
    request.post<ApiResponse<{ logged_out: boolean }>>('/api/admin/auth/logout')

/** GET /api/admin/auth/me —— 获取当前登录管理员的完整档案（账号、角色、权限、安全摘要） */
export const getAdminProfile = () =>
    request.get<ApiResponse<AdminProfile>>('/api/admin/auth/me')

/** GET /api/admin/users —— 分页获取管理员用户列表；params 传递分页与筛选条件 */
export const getAdminUsers = (params = {}) =>
    request.get<ApiResponse<{ items: AdminUser[]; pagination: any }>>('/api/admin/users', { params })

/** POST /api/admin/users —— 新建管理员用户；从 payload 中拆出 password 单独加密后再连同其余字段提交 */
export const createAdminUser = async (payload: any) => {
    return withPasswordKeyRetry(async () => {
        const { password, ...rest } = payload
        const encryptedPassword = await encryptAdminPassword(password)

        return request.post<ApiResponse<AdminUser>>('/api/admin/users', {
            ...rest,
            ...encryptedPassword,
        })
    })
}

/** PUT /api/admin/users/{id} —— 更新指定管理员用户的基础信息（不含改密） */
export const updateAdminUser = (id: number, payload: any) =>
    request.put<ApiResponse<AdminUser>>(`/api/admin/users/${id}`, payload)

/** POST /api/admin/users/{id}/reset-password —— 重置指定用户密码；新密码与确认密码分别加密后提交 */
export const resetAdminPassword = async (id: number, payload: any) => {
    return withPasswordKeyRetry(async () => {
        const password = await encryptAdminPassword(payload.password)
        const confirmation = await encryptAdminPassword(payload.password_confirmation)

        return request.post<ApiResponse<AdminUser>>(`/api/admin/users/${id}/reset-password`, {
            password_encrypted: password.password_encrypted,
            password_confirmation_encrypted: confirmation.password_encrypted,
            password_key_id: password.password_key_id,
        })
    })
}

/** POST /api/admin/users/{id}/two-factor/reset —— 管理员为指定用户重置（关闭）二次验证，用于破窗救援 */
export const resetAdminTwoFactor = (id: number) =>
    request.post<ApiResponse<AdminUser>>(`/api/admin/users/${id}/two-factor/reset`)

/** GET /api/admin/roles —— 获取全部角色及可分配的权限清单 */
export const getAdminRoles = () =>
    request.get<ApiResponse<{ roles: AdminRole[]; permissions: AdminPermission[] }>>('/api/admin/roles')

/** POST /api/admin/roles —— 新建角色 */
export const createAdminRole = (payload: any) =>
    request.post<ApiResponse<AdminRole>>('/api/admin/roles', payload)

/** PUT /api/admin/roles/{id} —— 更新指定角色（名称、权限等） */
export const updateAdminRole = (id: number, payload: any) =>
    request.put<ApiResponse<AdminRole>>(`/api/admin/roles/${id}`, payload)

/** GET /api/admin/audit-logs —— 分页查询审计日志；params 传递筛选与分页条件 */
export const getAdminAuditLogs = (params = {}) =>
    request.get<ApiResponse<{ items: AdminAuditLog[]; pagination: any }>>('/api/admin/audit-logs', { params })

/** GET /api/admin/audit-logs/export —— 按筛选条件导出审计日志为文件（Blob 响应） */
export const exportAdminAuditLogs = (params = {}) =>
    request.get<Blob>('/api/admin/audit-logs/export', {
        params,
        responseType: 'blob',
    })

/**
 * 审计日志可选维度
 * 供前端筛选下拉框使用的模块、动作、结果取值集合
 */
export interface AdminAuditFacets {
    modules: string[]
    actions: string[]
    results: string[]
}

/** GET /api/admin/audit-logs/facets —— 获取审计日志筛选项（模块/动作/结果的可选值） */
export const getAdminAuditFacets = () =>
    request.get<ApiResponse<AdminAuditFacets>>('/api/admin/audit-logs/facets')

/**
 * 审计日志筛选预设
 * 保存一组命名好的筛选条件（filters），供用户快速复用常用查询
 */
export interface AdminAuditPreset {
    id: number
    name: string
    filters: Record<string, string | number>
    created_at: string | null
}

/** GET /api/admin/audit-logs/presets —— 获取当前用户保存的审计筛选预设列表 */
export const getAuditPresets = () =>
    request.get<ApiResponse<{ items: AdminAuditPreset[] }>>('/api/admin/audit-logs/presets')

/** POST /api/admin/audit-logs/presets —— 保存一个命名的审计筛选预设 */
export const saveAuditPreset = (payload: { name: string; filters: Record<string, unknown> }) =>
    request.post<ApiResponse<{ preset: AdminAuditPreset }>>('/api/admin/audit-logs/presets', payload)

/** DELETE /api/admin/audit-logs/presets/{id} —— 删除指定审计筛选预设 */
export const deleteAuditPreset = (id: number) =>
    request.delete<ApiResponse<{ deleted: boolean }>>(`/api/admin/audit-logs/presets/${id}`)
