<template>
    <section class="alerts-page">
        <div class="summary-grid">
            <el-card v-for="item in summaryCards" :key="item.label" shadow="never" class="summary-card">
                <div class="summary-label">{{ item.label }}</div>
                <div class="summary-value" :class="item.className">{{ item.value }}</div>
                <div class="summary-desc">{{ item.description }}</div>
            </el-card>
        </div>

        <el-card shadow="never" class="alert-panel">
            <template #header>
                <div class="panel-header">
                    <div>
                        <div class="panel-title">告警中心</div>
                        <div class="panel-subtitle">实时告警、确认处理与通知状态入口</div>
                    </div>

                    <el-space>
                        <el-tag :type="realtimeConnected ? 'success' : 'info'" effect="plain">
                            {{ realtimeConnected ? '实时已连接' : '实时未连接' }}
                        </el-tag>
                        <el-button :loading="testingNotification" @click="handleTestNotification">
                            测试通知
                        </el-button>
                        <el-button :icon="Refresh" :loading="loading || evaluating" @click="handleEvaluate">
                            立即评估
                        </el-button>
                    </el-space>
                </div>
            </template>

            <div class="filters">
                <el-segmented v-model="status" :options="statusOptions" @change="handleFilterChange" />

                <el-select v-model="severity" clearable placeholder="级别" @change="handleFilterChange">
                    <el-option label="Critical" value="critical" />
                    <el-option label="Warning" value="warning" />
                    <el-option label="Info" value="info" />
                </el-select>

                <el-select v-model="source" clearable placeholder="来源" @change="handleFilterChange">
                    <el-option
                        v-for="item in summary.sources"
                        :key="item.source"
                        :label="item.source"
                        :value="item.source"
                    />
                </el-select>
            </div>

            <el-table :data="alerts" border stripe v-loading="loading" empty-text="暂无告警">
                <el-table-column label="级别" width="120">
                    <template #default="{ row }">
                        <el-tag :type="severityTag(row.severity)" effect="light">
                            {{ row.severity }}
                        </el-tag>
                    </template>
                </el-table-column>

                <el-table-column prop="source" label="来源" width="120" />

                <el-table-column label="告警内容" min-width="360">
                    <template #default="{ row }">
                        <div class="alert-title">{{ row.title }}</div>
                        <div class="alert-message">{{ row.message }}</div>
                    </template>
                </el-table-column>

                <el-table-column label="状态" width="130">
                    <template #default="{ row }">
                        <el-tag :type="row.status === 'open' ? 'danger' : 'info'" effect="plain">
                            {{ statusLabel(row.status) }}
                        </el-tag>
                    </template>
                </el-table-column>

                <el-table-column prop="hit_count" label="次数" width="90" />
                <el-table-column prop="last_seen_at" label="最后出现" width="180" />

                <el-table-column label="操作" width="150" fixed="right">
                    <template #default="{ row }">
                        <el-button
                            v-if="row.status === 'open'"
                            text
                            type="primary"
                            :loading="acknowledgingId === row.id"
                            @click="handleAcknowledge(row)"
                        >
                            确认
                        </el-button>
                        <span v-else class="muted">{{ row.acknowledged_by || '-' }}</span>
                    </template>
                </el-table-column>
            </el-table>

            <div class="pagination-bar">
                <el-pagination
                    v-model:current-page="page"
                    v-model:page-size="perPage"
                    :page-sizes="[10, 20, 50, 100]"
                    :total="pagination.total"
                    background
                    layout="total, sizes, prev, pager, next, jumper"
                    @current-change="loadAlerts"
                    @size-change="handlePageSizeChange"
                />
            </div>
        </el-card>
    </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import echo from '@/utils/echo'
import {
    acknowledgeAlert,
    evaluateAlerts,
    getAlerts,
    getAlertSummary,
    testAlertNotification,
    type AlertRealtimePayload,
    type AlertSummary,
    type AlertStatus,
    type OpsAlert,
} from '@/api/opsStage4'

const loading = ref(false)
const evaluating = ref(false)
const testingNotification = ref(false)
const realtimeConnected = ref(false)
const acknowledgingId = ref<number | null>(null)
const alerts = ref<OpsAlert[]>([])
const status = ref<AlertStatus | 'all'>('open')
const severity = ref('')
const source = ref('')
const page = ref(1)
const perPage = ref(20)
const pagination = ref({
    current_page: 1,
    per_page: 20,
    total: 0,
    last_page: 1,
})
const summary = ref<AlertSummary>({
    open_total: 0,
    critical: 0,
    warning: 0,
    info: 0,
    sources: [],
    checked_at: '-',
})
let channel: any = null

const statusOptions = [
    { label: 'Open', value: 'open' },
    { label: '已确认', value: 'acknowledged' },
    { label: '全部', value: 'all' },
]

const summaryCards = computed(() => [
    {
        label: 'Open',
        value: summary.value.open_total,
        description: '当前未处理告警',
        className: 'open',
    },
    {
        label: 'Critical',
        value: summary.value.critical,
        description: '需要立即关注',
        className: 'critical',
    },
    {
        label: 'Warning',
        value: summary.value.warning,
        description: '达到预警阈值',
        className: 'warning',
    },
    {
        label: 'Info',
        value: summary.value.info,
        description: '提示类事件',
        className: 'info',
    },
])

/**
 * 加载告警汇总。
 */
const loadSummary = async () => {
    const res = await getAlertSummary()
    summary.value = res.data.data
}

/**
 * 加载告警列表。
 */
const loadAlerts = async () => {
    loading.value = true

    try {
        const res = await getAlerts({
            status: status.value === 'all' ? undefined : status.value,
            severity: severity.value || undefined,
            source: source.value || undefined,
            page: page.value,
            per_page: perPage.value,
        })

        alerts.value = res.data.data.items
        pagination.value = res.data.data.pagination
        page.value = pagination.value.current_page
        perPage.value = pagination.value.per_page
    } catch {
        ElMessage.error('告警列表加载失败')
    } finally {
        loading.value = false
    }
}

/**
 * 手动执行一次告警评估。
 */
const handleEvaluate = async () => {
    evaluating.value = true

    try {
        const res = await evaluateAlerts()
        summary.value = res.data.data.summary
        await loadAlerts()
        ElMessage.success(`评估完成，命中 ${res.data.data.detected} 条规则`)
    } catch {
        ElMessage.error('告警评估失败')
    } finally {
        evaluating.value = false
    }
}

/**
 * 测试 Telegram / 邮件通知通道。
 */
const handleTestNotification = async () => {
    testingNotification.value = true

    try {
        const res = await testAlertNotification({
            channels: ['telegram', 'mail'],
            message: 'Ops Center 告警中心通知通道测试。',
        })
        const result = res.data.data.result
        const sentChannels = Object.entries(result)
            .filter(([, item]) => item.sent)
            .map(([channel]) => channel)

        if (sentChannels.length > 0) {
            ElMessage.success(`通知测试成功：${sentChannels.join(', ')}`)
            return
        }

        ElMessage.warning('通知测试未发送，请检查 Telegram / 邮件配置是否启用')
    } catch {
        ElMessage.error('通知测试失败')
    } finally {
        testingNotification.value = false
    }
}

/**
 * 筛选条件变化后回到第一页。
 */
const handleFilterChange = async () => {
    page.value = 1
    await loadAlerts()
}

/**
 * 每页条数变化后回到第一页。
 */
const handlePageSizeChange = async () => {
    page.value = 1
    await loadAlerts()
}

/**
 * 确认告警。
 */
const handleAcknowledge = async (alert: OpsAlert) => {
    try {
        const { value } = await ElMessageBox.prompt('填写确认备注，可留空', '确认告警', {
            confirmButtonText: '确认',
            cancelButtonText: '取消',
            inputPlaceholder: '例如：已处理、观察中',
        })

        acknowledgingId.value = alert.id
        await acknowledgeAlert(alert.id, {
            acknowledged_by: 'ops-user',
            note: value,
        })

        ElMessage.success('告警已确认')
        await Promise.all([loadSummary(), loadAlerts()])
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('告警确认失败')
        }
    } finally {
        acknowledgingId.value = null
    }
}

/**
 * 建立告警 WebSocket 监听。
 */
const startRealtime = () => {
    if (channel) {
        return
    }

    channel = echo.channel('ops.alerts')
        .listen('.alert.triggered', async (payload: AlertRealtimePayload) => {
            realtimeConnected.value = true
            ElMessage.warning(`${payload.source}: ${payload.title}`)
            await Promise.all([loadSummary(), loadAlerts()])
        })
        .error(() => {
            realtimeConnected.value = false
        })

    realtimeConnected.value = true
}

/**
 * 离开页面时释放频道。
 */
const stopRealtime = () => {
    if (channel) {
        echo.leaveChannel('ops.alerts')
        channel = null
    }

    realtimeConnected.value = false
}

const severityTag = (value: string) => {
    if (value === 'critical') {
        return 'danger'
    }

    if (value === 'warning') {
        return 'warning'
    }

    return 'info'
}

const statusLabel = (value: string) => {
    if (value === 'open') {
        return '未处理'
    }

    if (value === 'acknowledged') {
        return '已确认'
    }

    return '已恢复'
}

onMounted(async () => {
    await Promise.all([loadSummary(), loadAlerts()])
    startRealtime()
})

onBeforeUnmount(stopRealtime)
</script>

<style scoped>
.alerts-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.summary-grid {
    display: grid;
    gap: 14px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-card {
    border-radius: 8px;
}

.summary-label {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.summary-value {
    color: #111827;
    font-size: 30px;
    font-weight: 800;
    margin-top: 8px;
}

.summary-value.critical {
    color: #dc2626;
}

.summary-value.warning {
    color: #d97706;
}

.summary-value.info {
    color: #2563eb;
}

.summary-value.open {
    color: #111827;
}

.summary-desc {
    color: #94a3b8;
    font-size: 12px;
    margin-top: 4px;
}

.panel-header,
.filters,
.pagination-bar {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.panel-title {
    color: #111827;
    font-size: 18px;
    font-weight: 700;
}

.panel-subtitle {
    color: #64748b;
    font-size: 13px;
    margin-top: 4px;
}

.filters {
    gap: 12px;
    justify-content: flex-start;
    margin-bottom: 14px;
}

.filters :deep(.el-select) {
    width: 160px;
}

.alert-title {
    color: #111827;
    font-weight: 700;
}

.alert-message {
    color: #64748b;
    font-size: 12px;
    line-height: 1.6;
    margin-top: 4px;
}

.muted {
    color: #94a3b8;
    font-size: 12px;
}

.pagination-bar {
    justify-content: flex-end;
    padding-top: 14px;
}

@media (max-width: 1100px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 760px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .panel-header,
    .filters {
        align-items: stretch;
        flex-direction: column;
    }

    .filters :deep(.el-select) {
        width: 100%;
    }
}
</style>
