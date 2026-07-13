import { defineStore } from 'pinia'
import { adminLogin, adminLogout, getAdminProfile, type AdminProfile } from '@/api/adminSecurity'

export const useAdminAuthStore = defineStore('adminAuth', {
    state: () => ({
        profile: null as AdminProfile | null,
        loaded: false,
    }),
    getters: {
        isAuthenticated: state => Boolean(state.profile?.admin),
        permissions: state => state.profile?.permissions ?? [],
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
            this.profile = res.data.data
            this.loaded = true
        },
        async logout() {
            await adminLogout()
            this.profile = null
            this.loaded = true
        },
    },
})
