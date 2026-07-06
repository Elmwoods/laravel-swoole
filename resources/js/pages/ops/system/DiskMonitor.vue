<template>
    <section class="disk-monitor">
        <div class="summary-grid">
            <el-card shadow="never">
                <el-statistic title="总容量" :value="summary.total_gb" suffix="GB" />
            </el-card>

            <el-card shadow="never">
                <el-statistic title="已使用" :value="summary.used_gb" suffix="GB" />
            </el-card>

            <el-card shadow="never">
                <el-statistic title="可用容量" :value="summary.available_gb" suffix="GB" />
            </el-card>

            <el-card shadow="never">
                <el-statistic title="最高使用率" :value="summary.max_usage" suffix="%" />
            </el-card>
        </div>

        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <span>Disk 使用率监控</span>

                    <el-space>
                        <span class="time">更新时间：{{ summary.checked_at || '-' }}</span>
                        <el-button :icon="Refresh" :loading="loading" @click="fetchData">
                            刷新
                        </el-button>
                    </el-space>
                </div>
            </template>

            <el-alert
                v-if="emptyMessage"
                class="disk-alert"
                :title="emptyMessage"
                type="warning"
                show-icon
                :closable="false"
            />

            <el-table
                :data="summary.disks"
                border
                stripe
                v-loading="loading"
                empty-text="暂无可展示的磁盘数据"
            >
                <el-table-column prop="filesystem" label="磁盘" min-width="180" show-overflow-tooltip />
                <el-table-column prop="mount" label="挂载点" min-width="160" show-overflow-tooltip />
                <el-table-column prop="size" label="总容量 GB" width="120" />
                <el-table-column prop="used" label="已用 GB" width="120" />
                <el-table-column prop="available" label="可用 GB" width="120" />

                <el-table-column label="使用率" min-width="220">
                    <template #default="{ row }">
                        <el-progress
                            :percentage="row.usage"
                            :status="row.usage >= 90 ? 'exception' : row.usage >= 75 ? 'warning' : 'success'"
                        />
                    </template>
                </el-table-column>
            </el-table>
        </el-card>
    </section>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import echo from '@/utils/echo'
import { getDiskSummary, type DiskSummary } from '@/api/opsStage2'

const loading = ref(false)
let channel: any = null
let fallbackTimer: number | null = null
let staleCheckTimer: number | null = null
let lastRealtimeAt = 0

const summary = ref<DiskSummary>({
    status: 'empty',
    source: '',
    message: null,
    total_gb: 0,
    used_gb: 0,
    available_gb: 0,
    max_usage: 0,
    checked_at: '',
    disks: [],
})

const emptyMessage = ref('')

/**
 * 规范化后端返回值，避免异常响应导致页面空白。
 */
const applyDiskSummary = (data?: Partial<DiskSummary> | DiskSummary['disks']) => {
    const legacyDisks = Array.isArray(data) ? data : []
    const disks = Array.isArray(data)
        ? data
        : (Array.isArray(data?.disks) ? data.disks : [])

    const totalGb = legacyDisks.length
        ? legacyDisks.reduce((total, item) => total + Number(item.size ?? 0), 0)
        : Number(data?.total_gb ?? 0)
    const usedGb = legacyDisks.length
        ? legacyDisks.reduce((total, item) => total + Number(item.used ?? 0), 0)
        : Number(data?.used_gb ?? 0)
    const availableGb = legacyDisks.length
        ? legacyDisks.reduce((total, item) => total + Number(item.available ?? 0), 0)
        : Number(data?.available_gb ?? 0)
    const maxUsage = disks.length
        ? Math.max(...disks.map(item => Number(item.usage ?? 0)))
        : Number(data?.max_usage ?? 0)

    summary.value = {
        status: data?.status ?? (disks.length ? 'ok' : 'empty'),
        source: data?.source ?? '',
        message: data?.message ?? null,
        total_gb: Number(totalGb.toFixed(2)),
        used_gb: Number(usedGb.toFixed(2)),
        available_gb: Number(availableGb.toFixed(2)),
        max_usage: maxUsage,
        checked_at: data?.checked_at ?? new Date().toLocaleString(),
        disks,
    }

    emptyMessage.value = disks.length
        ? ''
        : (summary.value.message || '未获取到可展示的磁盘数据，请检查容器内 df 命令或 Octane Worker 是否已重载')
}

/**
 * 获取磁盘汇总和分区明细。
 */
const fetchData = async () => {
    loading.value = true

    try {
        const res = await getDiskSummary()
        applyDiskSummary(res.data.data)
    } catch {
        emptyMessage.value = '磁盘数据加载失败，请检查 /api/ops/system/disk 接口'
        ElMessage.error('磁盘数据加载失败')
    } finally {
        loading.value = false
    }
}

const fetchDataSilently = async () => {
    try {
        const res = await getDiskSummary()
        applyDiskSummary(res.data.data)
    } catch {
        // 静默兜底轮询失败不打扰用户，手动刷新仍会展示错误。
    }
}

/**
 * Disk 优先使用 WebSocket；若长时间没有推送，再启用 HTTP 兜底。
 */
const ensureFallbackPolling = () => {
    if (Date.now() - lastRealtimeAt < 15000) {
        if (fallbackTimer) {
            window.clearInterval(fallbackTimer)
            fallbackTimer = null
        }

        return
    }

    if (!fallbackTimer) {
        fallbackTimer = window.setInterval(fetchDataSilently, 10000)
    }
}

onMounted(() => {
    fetchData()

    try {
        channel = echo.channel('ops.system.disk')
            .listen('.disk.updated', (event: { data?: DiskSummary }) => {
                const data = event?.data ?? event

                if (data) {
                    lastRealtimeAt = Date.now()
                    applyDiskSummary(data)
                }
            })
    } catch {
        ensureFallbackPolling()
    }

    staleCheckTimer = window.setInterval(ensureFallbackPolling, 5000)
})

onBeforeUnmount(() => {
    if (channel) {
        echo.leaveChannel('ops.system.disk')
        channel = null
    }

    if (fallbackTimer) {
        window.clearInterval(fallbackTimer)
    }

    if (staleCheckTimer) {
        window.clearInterval(staleCheckTimer)
    }
})
</script>

<style scoped>
.disk-monitor {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.summary-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.time {
    color: #6b7280;
    font-size: 13px;
}

.disk-alert {
    margin-bottom: 16px;
}

@media (max-width: 900px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>
