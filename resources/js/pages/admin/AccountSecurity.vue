<template>
    <!-- 账号安全中心：四张卡片分别管理 2FA / 登录历史 / 受信任设备 / 活跃会话 -->
    <div class="account-security">
        <!-- 卡片一：二次验证状态，头部标签实时反映是否已启用 -->
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

            <!-- 未启用 2FA 时才提示强制绑定，已启用则隐藏，避免干扰 -->
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

        <!-- 卡片二：最近 20 次登录历史，供用户核对异常登录 -->
        <el-card shadow="never" class="section">
            <template #header>
                <div class="section-header">
                    <span>登录历史（最近 20 次）</span>
                    <el-button :loading="loadingHistory" text type="primary" @click="loadHistory">刷新</el-button>
                </div>
            </template>

            <el-table v-loading="loadingHistory" :data="loginEvents" size="small">
                <el-table-column label="时间" prop="created_at" width="180" />
                <!-- IP 列：首次出现的 IP 追加「新 IP」告警徽标，提示潜在异地登录 -->
                <el-table-column label="IP" min-width="160">
                    <template #default="{ row }">
                        <span>{{ row.ip_address || '—' }}</span>
                        <el-tag v-if="row.is_new_ip" type="warning" size="small" effect="plain" class="badge">
                            新 IP
                        </el-tag>
                    </template>
                </el-table-column>
                <!-- 设备/UA 列：首次出现的设备追加「新设备」徽标，同样用于异常识别 -->
                <el-table-column label="设备 / UA" min-width="260">
                    <template #default="{ row }">
                        <span class="ua">{{ row.user_agent || '—' }}</span>
                        <el-tag v-if="row.is_new_user_agent" type="warning" size="small" effect="plain" class="badge">
                            新设备
                        </el-tag>
                    </template>
                </el-table-column>
                <!-- 方式列：区分本次登录是走「受信任设备」免验证还是完整「二次验证」 -->
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

        <!-- 卡片三：受信任设备清单，可撤销单个设备的免二次验证信任 -->
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
                <!-- 操作列：撤销该设备信任；用 revokingId 精确控制当前行按钮 loading -->
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

        <!-- 卡片四：活跃会话，可注销单个会话或一键注销其他所有会话 -->
        <el-card shadow="never" class="section">
            <template #header>
                <div class="section-header">
                    <span>活跃会话</span>
                    <div class="header-actions">
                        <!-- 没有其他会话时禁用「注销其他会话」，避免无意义点击 -->
                        <el-button
                            :loading="revokingOthers"
                            :disabled="otherSessionCount === 0"
                            text
                            type="danger"
                            @click="revokeOthers"
                        >
                            注销其他会话
                        </el-button>
                        <el-button :loading="loadingSessions" text type="primary" @click="loadSessions">刷新</el-button>
                    </div>
                </div>
            </template>

            <el-table v-loading="loadingSessions" :data="activeSessions" size="small">
                <!-- 设备列：给当前正在使用的会话打「当前会话」徽标，防止误注销自己 -->
                <el-table-column label="设备" min-width="150">
                    <template #default="{ row }">
                        <span>{{ row.label || '未知' }}</span>
                        <el-tag v-if="row.current" type="success" size="small" effect="plain" class="badge">
                            当前会话
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="IP" prop="ip_address" width="150" />
                <el-table-column label="UA" prop="user_agent" min-width="240" />
                <el-table-column label="最近活动" prop="last_activity_at" width="180" />
                <!-- 操作列：仅非当前会话可注销，当前会话显示占位符 -->
                <el-table-column label="操作" width="100" fixed="right">
                    <template #default="{ row }">
                        <el-button
                            v-if="!row.current"
                            :loading="revokingSessionId === row.id"
                            text
                            type="danger"
                            @click="revokeOne(row.id)"
                        >
                            注销
                        </el-button>
                        <span v-else class="muted">—</span>
                    </template>
                </el-table-column>
                <template #empty>
                    <el-empty description="暂无活跃会话" />
                </template>
            </el-table>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
    getActiveSessions,
    getLoginHistory,
    getTrustedDevices,
    revokeOtherSessions,
    revokeSession,
    revokeTrustedDevice,
    type AdminActiveSession,
    type AdminLoginEvent,
    type AdminTrustedDevice,
} from '@/api/accountSecurity'
import { useAdminAuthStore } from '@/stores/adminAuth'

// 管理端鉴权 store：2FA 状态来自 profile，此处只读展示
const auth = useAdminAuthStore()
// 登录历史列表（最近 20 次）
const loginEvents = ref<AdminLoginEvent[]>([])
// 受信任设备列表
const trustedDevices = ref<AdminTrustedDevice[]>([])
// 活跃会话列表
const activeSessions = ref<AdminActiveSession[]>([])
// 三块列表各自独立的加载态，互不阻塞，避免一处刷新遮挡整页
const loadingHistory = ref(false)
const loadingDevices = ref(false)
const loadingSessions = ref(false)
// 记录正在撤销的设备 id：用于只让对应那一行按钮转圈，而非全表 loading
const revokingId = ref<number | null>(null)
// 记录正在注销的会话 id，作用同上
const revokingSessionId = ref<number | null>(null)
// 「注销其他会话」批量操作的进行中标志
const revokingOthers = ref(false)

// 从 profile 中解构 2FA 安全信息；profile 可能尚未加载，兜底为 null
const security = computed(() => auth.profile?.security ?? null)
// 除当前会话外的其他会话数量：用于决定「注销其他会话」按钮是否可点
const otherSessionCount = computed(() => activeSessions.value.filter(session => !session.current).length)

// 拉取登录历史，供用户核对是否有陌生 IP/设备
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

// 拉取受信任设备清单
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

// 撤销单个受信任设备。
// 为什么：先弹确认框告知「撤销后该设备需重新走 2FA」，用户点确认才发请求，
// 成功后重新拉取列表刷新状态。
const revoke = async (id: number) => {
    try {
        await ElMessageBox.confirm('撤销后，该设备下次登录需重新完成二次验证。', '撤销受信任设备', {
            type: 'warning',
            confirmButtonText: '撤销',
            cancelButtonText: '取消',
        })
    } catch {
        // 用户在确认框点了取消，直接静默返回
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

// 拉取当前账号的所有活跃会话
const loadSessions = async () => {
    loadingSessions.value = true

    try {
        const res = await getActiveSessions()
        activeSessions.value = res.data.data.sessions
    } catch {
        ElMessage.error('加载活跃会话失败')
    } finally {
        loadingSessions.value = false
    }
}

// 注销单个会话（非当前会话）。
// 为什么：同样先确认再执行，成功后刷新会话列表，让被踢下线的会话立即从表中消失。
const revokeOne = async (id: number) => {
    try {
        await ElMessageBox.confirm('注销后，该会话下次操作需要重新登录。', '注销会话', {
            type: 'warning',
            confirmButtonText: '注销',
            cancelButtonText: '取消',
        })
    } catch {
        // 取消注销，静默返回
        return
    }

    revokingSessionId.value = id

    try {
        await revokeSession(id)
        ElMessage.success('已注销')
        await loadSessions()
    } catch {
        ElMessage.error('注销失败')
    } finally {
        revokingSessionId.value = null
    }
}

// 一键注销除当前会话外的所有会话。
// 为什么：账号疑似被盗时可快速踢掉所有其他登录，仅保留当前操作会话。
const revokeOthers = async () => {
    try {
        await ElMessageBox.confirm('将注销除当前会话外的所有登录会话。', '注销其他会话', {
            type: 'warning',
            confirmButtonText: '注销',
            cancelButtonText: '取消',
        })
    } catch {
        // 取消批量注销，静默返回
        return
    }

    revokingOthers.value = true

    try {
        await revokeOtherSessions()
        ElMessage.success('已注销其他会话')
        await loadSessions()
    } catch {
        ElMessage.error('注销失败')
    } finally {
        revokingOthers.value = false
    }
}

// 首次挂载：profile 未加载则先补拉（2FA 状态依赖它），再并发拉取三块列表数据。
// 为什么：三个接口互不依赖，用 Promise.all 并发以缩短首屏等待时间。
onMounted(async () => {
    if (!auth.loaded) {
        await auth.loadProfile()
    }

    await Promise.all([loadHistory(), loadDevices(), loadSessions()])
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

.header-actions {
    align-items: center;
    display: flex;
    gap: 4px;
}

.muted {
    color: #9ca3af;
}
</style>
