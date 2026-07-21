<template>
    <div class="account-security">
        <el-card shadow="never" class="section">
            <template #header>
                <div class="section-header">
                    <span>二次验证</span>
                    <el-tag :type="security?.two_factor_enabled ? 'success' : 'danger'" effect="plain">
                        {{ security?.two_factor_enabled ? '已启用' : '未启用' }}
                    </el-tag>
                </div>
            </template>

            <el-descriptions :column="1" border>
                <el-descriptions-item label="绑定时间">
                    {{ security?.two_factor_confirmed_at || '—' }}
                </el-descriptions-item>
                <el-descriptions-item label="上次登录时间">
                    {{ security?.last_login_at || '—' }}
                </el-descriptions-item>
                <el-descriptions-item label="上次登录 IP">
                    {{ security?.last_login_ip || '—' }}
                </el-descriptions-item>
                <el-descriptions-item label="当前 IP">
                    {{ security?.current_ip || '—' }}
                </el-descriptions-item>
            </el-descriptions>

            <el-alert
                v-if="!security?.two_factor_enabled"
                :closable="false"
                show-icon
                type="warning"
                class="hint"
            >
                所有后台管理员必须绑定认证器应用。如需重置，请联系超级管理员或使用 CLI 紧急重置。
            </el-alert>
        </el-card>

        <el-card shadow="never" class="section">
            <template #header>
                <div class="section-header">
                    <span>登录历史（最近 20 次）</span>
                    <el-button :loading="loadingHistory" text type="primary" @click="loadHistory">刷新</el-button>
                </div>
            </template>

            <el-table v-loading="loadingHistory" :data="loginEvents" size="small">
                <el-table-column label="时间" prop="created_at" width="180" />
                <el-table-column label="IP" min-width="160">
                    <template #default="{ row }">
                        <span>{{ row.ip_address || '—' }}</span>
                        <el-tag v-if="row.is_new_ip" type="warning" size="small" effect="plain" class="badge">
                            新 IP
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="设备 / UA" min-width="260">
                    <template #default="{ row }">
                        <span class="ua">{{ row.user_agent || '—' }}</span>
                        <el-tag v-if="row.is_new_user_agent" type="warning" size="small" effect="plain" class="badge">
                            新设备
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="方式" width="110">
                    <template #default="{ row }">
                        <el-tag :type="row.trusted ? 'info' : 'success'" size="small" effect="plain">
                            {{ row.trusted ? '受信任设备' : '二次验证' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <template #empty>
                    <el-empty description="暂无登录记录" />
                </template>
            </el-table>
        </el-card>

        <el-card shadow="never" class="section">
            <template #header>
                <div class="section-header">
                    <span>受信任设备</span>
                    <el-button :loading="loadingDevices" text type="primary" @click="loadDevices">刷新</el-button>
                </div>
            </template>

            <el-table v-loading="loadingDevices" :data="trustedDevices" size="small">
                <el-table-column label="设备" prop="label" min-width="150" />
                <el-table-column label="最近 IP" prop="last_ip" width="150" />
                <el-table-column label="最近 UA" prop="last_user_agent" min-width="240" />
                <el-table-column label="过期时间" prop="expires_at" width="180" />
                <el-table-column label="操作" width="100" fixed="right">
                    <template #default="{ row }">
                        <el-button
                            :loading="revokingId === row.id"
                            text
                            type="danger"
                            @click="revoke(row.id)"
                        >
                            撤销
                        </el-button>
                    </template>
                </el-table-column>
                <template #empty>
                    <el-empty description="暂无受信任设备" />
                </template>
            </el-table>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
    getLoginHistory,
    getTrustedDevices,
    revokeTrustedDevice,
    type AdminLoginEvent,
    type AdminTrustedDevice,
} from '@/api/accountSecurity'
import { useAdminAuthStore } from '@/stores/adminAuth'

const auth = useAdminAuthStore()
const loginEvents = ref<AdminLoginEvent[]>([])
const trustedDevices = ref<AdminTrustedDevice[]>([])
const loadingHistory = ref(false)
const loadingDevices = ref(false)
const revokingId = ref<number | null>(null)

const security = computed(() => auth.profile?.security ?? null)

const loadHistory = async () => {
    loadingHistory.value = true

    try {
        const res = await getLoginHistory()
        loginEvents.value = res.data.data.events
    } catch {
        ElMessage.error('加载登录历史失败')
    } finally {
        loadingHistory.value = false
    }
}

const loadDevices = async () => {
    loadingDevices.value = true

    try {
        const res = await getTrustedDevices()
        trustedDevices.value = res.data.data.devices
    } catch {
        ElMessage.error('加载受信任设备失败')
    } finally {
        loadingDevices.value = false
    }
}

const revoke = async (id: number) => {
    try {
        await ElMessageBox.confirm('撤销后，该设备下次登录需重新完成二次验证。', '撤销受信任设备', {
            type: 'warning',
            confirmButtonText: '撤销',
            cancelButtonText: '取消',
        })
    } catch {
        return
    }

    revokingId.value = id

    try {
        await revokeTrustedDevice(id)
        ElMessage.success('已撤销')
        await loadDevices()
    } catch {
        ElMessage.error('撤销失败')
    } finally {
        revokingId.value = null
    }
}

onMounted(async () => {
    if (!auth.loaded) {
        await auth.loadProfile()
    }

    await Promise.all([loadHistory(), loadDevices()])
})
</script>

<style scoped>
.account-security {
    display: grid;
    gap: 20px;
    max-width: 960px;
}

.section-header {
    align-items: center;
    display: flex;
    gap: 12px;
    justify-content: space-between;
}

.hint {
    margin-top: 16px;
}

.badge {
    margin-left: 8px;
}

.ua {
    word-break: break-all;
}
</style>
