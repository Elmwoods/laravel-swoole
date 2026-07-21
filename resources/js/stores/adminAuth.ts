import { defineStore } from 'pinia'
import {
    adminLogin,
    adminLogout,
    challengeAdminTwoFactor,
    confirmAdminTwoFactor,
    getAdminProfile,
    type AdminLoginResult,
    type AdminProfile,
} from '@/api/adminSecurity'

export const useAdminAuthStore = defineStore('adminAuth', {
    state: () => ({
        profile: null as AdminProfile | null,
        loaded: false,
    }),
    getters: {
        isAuthenticated: state => Boolean(state.profile?.admin),
        permissions: state => state.profile?.permissions ?? [],
        isSuperAdmin: state => Boolean(
            state.profile?.admin?.is_active &&
            state.profile?.roles?.some(role => role.slug === 'super_admin' && role.is_active),
        ),
    },
    actions: {
        hasPermission(permission: string) {
            return this.permissions.includes(permission)
        },
        async loadProfile() {
            try {
                const res = await getAdminProfile()
                this.profile = res.data.data
            } catch {
                this.profile = null
            } finally {
                this.loaded = true
            }
        },
        async login(payload: { email: string; password: string }) {
            const res = await adminLogin(payload)
            const data = res.data.data

            if (data.admin) {
                this.profile = data as AdminProfile
                this.loaded = true
            }

            return data as AdminLoginResult
        },
        async confirmTwoFactor(payload: { code: string; trust_device?: boolean }) {
            const res = await confirmAdminTwoFactor(payload)
            this.profile = res.data.data.profile
            this.loaded = true

            return res.data.data
        },
        async challengeTwoFactor(payload: { code?: string; recovery_code?: string; trust_device?: boolean }) {
            const res = await challengeAdminTwoFactor(payload)
            this.profile = res.data.data
            this.loaded = true

            return res.data.data
        },
        async logout() {
            await adminLogout()
            this.profile = null
            this.loaded = true
        },
    },
})
