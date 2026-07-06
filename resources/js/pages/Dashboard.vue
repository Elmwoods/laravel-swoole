<template>
    <section class="dashboard">
        <div class="summary-grid">
            <el-card
                v-for="item in summaryCards"
                :key="item.title"
                shadow="never"
                class="summary-card"
            >
                <div class="summary-head">
                    <el-icon :class="item.iconClass">
                        <component :is="item.icon" />
                    </el-icon>

                    <el-tag :type="item.tagType" effect="plain">
                        {{ item.status }}
                    </el-tag>
                </div>

                <div class="summary-title">{{ item.title }}</div>
                <div class="summary-value">{{ item.value }}</div>
                <div class="summary-desc">{{ item.desc }}</div>
            </el-card>
        </div>

        <div class="toolbar">
            <el-space wrap>
                <el-button :icon="Refresh" :loading="loading" @click="fetch">
                    刷新
                </el-button>

                <el-button type="primary" :icon="Switch" :loading="actionLoading" @click="reload">
                    Reload Octane
                </el-button>
            </el-space>

            <div class="checked-at">
                最后刷新：{{ checkedAt || '-' }}
            </div>
        </div>

        <el-row :gutter="20">
            <el-col :xs="24" :xl="16">
                <AdvancedSystemChart />
            </el-col>

            <el-col :xs="24" :xl="8">
                <el-card shadow="never" class="quick-card">
                    <template #header>
                        <div class="card-header">服务快捷入口</div>
                    </template>

                    <div class="quick-list">
                        <router-link
                            v-for="service in services"
                            :key="service.path"
                            :to="service.path"
                            class="quick-item"
                        >
                            <el-icon>
                                <component :is="service.icon" />
                            </el-icon>

                            <div class="quick-copy">
                                <div class="quick-title">{{ service.title }}</div>
                                <div class="quick-desc">{{ service.desc }}</div>
                            </div>

                            <el-icon class="quick-arrow"><ArrowRight /></el-icon>
                        </router-link>
                    </div>
                </el-card>
            </el-col>
        </el-row>

        <el-row :gutter="20" class="lower-row">
            <el-col :xs="24" :lg="10">
                <SystemCard />
            </el-col>

            <el-col :xs="24" :lg="14">
                <SystemChart />
            </el-col>
        </el-row>
    </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
    ArrowRight,
    Box,
    Coin,
    Connection,
    Cpu,
    DataLine,
    Document,
    FolderOpened,
    List,
    Monitor,
    Operation,
    Refresh,
    Switch,
} from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import SystemCard from '@/components/SystemCard.vue'
import SystemChart from '@/components/SystemChart.vue'
import AdvancedSystemChart from '@/components/AdvancedSystemChart.vue'
import { getDashboard, reloadOctane } from '@/api/ops'

interface DashboardData {
    system?: {
        cpu_load?: number | string
        memory?: {
            used_mb?: number
        }
    }
    redis?: {
        connected?: boolean
    }
    mysql?: {
        connected?: boolean
    }
    octane?: {
        running?: boolean
        process_count?: number
        configured_workers?: number
    }
}

const data = ref<DashboardData | null>(null)
const loading = ref(false)
const actionLoading = ref(false)
const checkedAt = ref('')
let timer: number | null = null

const serviceStatus = (online?: boolean) => online ? '正常' : '异常'
const serviceTag = (online?: boolean) => online ? 'success' : 'danger'

/**
 * 首页核心状态卡片。
 */
const summaryCards = computed(() => [
    {
        title: 'Octane',
        value: data.value?.octane?.running ? 'Running' : 'Stopped',
        desc: `进程：${data.value?.octane?.process_count ?? 0} / Worker：${data.value?.octane?.configured_workers ?? 0}`,
        status: serviceStatus(data.value?.octane?.running),
        tagType: serviceTag(data.value?.octane?.running),
        icon: Cpu,
        iconClass: 'icon-blue',
    },
    {
        title: 'Redis',
        value: data.value?.redis?.connected ? 'Connected' : 'Disconnected',
        desc: '缓存与队列连接状态',
        status: serviceStatus(data.value?.redis?.connected),
        tagType: serviceTag(data.value?.redis?.connected),
        icon: Coin,
        iconClass: 'icon-green',
    },
    {
        title: 'MySQL',
        value: data.value?.mysql?.connected ? 'Connected' : 'Disconnected',
        desc: '业务数据库连接状态',
        status: serviceStatus(data.value?.mysql?.connected),
        tagType: serviceTag(data.value?.mysql?.connected),
        icon: DataLine,
        iconClass: 'icon-violet',
    },
    {
        title: 'Memory',
        value: `${data.value?.system?.memory?.used_mb ?? 0} MB`,
        desc: `CPU Load：${data.value?.system?.cpu_load ?? '-'}`,
        status: '实时',
        tagType: 'info',
        icon: Monitor,
        iconClass: 'icon-slate',
    },
])

/**
 * 侧边栏之外的服务快捷入口，方便首页快速跳转。
 */
const services = [
    {
        title: 'Octane Worker',
        desc: '查看 Worker 数量和 Reload 状态',
        path: '/admin/ops/octane',
        icon: Cpu,
    },
    {
        title: 'Redis Monitor',
        desc: '查看连接、QPS、内存和持久化',
        path: '/admin/ops/redis',
        icon: Coin,
    },
    {
        title: 'Queue Monitor',
        desc: '查看队列堆积、失败任务和 Worker',
        path: '/admin/ops/queue',
        icon: List,
    },
    {
        title: 'Supervisor',
        desc: '管理 Octane、Queue、Reverb 等进程',
        path: '/admin/ops/supervisor',
        icon: Operation,
    },
    {
        title: 'Docker Containers',
        desc: '查看容器状态、资源和日志',
        path: '/admin/ops/docker',
        icon: Box,
    },
    {
        title: 'Network Traffic',
        desc: '查看网络实时吞吐',
        path: '/admin/ops/network',
        icon: Connection,
    },
    {
        title: 'Disk Usage',
        desc: '查看磁盘和分区使用率',
        path: '/admin/ops/system/disk-monitor',
        icon: FolderOpened,
    },
    {
        title: 'Logs',
        desc: '查看 Laravel、Octane、Redis 日志',
        path: '/admin/ops/logs',
        icon: Document,
    },
]

/**
 * 获取首页汇总数据。
 */
const fetch = async () => {
    loading.value = true

    try {
        const res = await getDashboard()
        data.value = res.data.data
        checkedAt.value = new Date().toLocaleTimeString()
    } catch {
        ElMessage.error('首页监控数据加载失败')
    } finally {
        loading.value = false
    }
}

/**
 * 平滑重载 Octane Worker。
 */
const reload = async () => {
    actionLoading.value = true

    try {
        await reloadOctane()
        ElMessage.success('Octane Reload 已发送')
        await fetch()
    } finally {
        actionLoading.value = false
    }
}

onMounted(() => {
    fetch()
    timer = window.setInterval(fetch, 5000)
})

onBeforeUnmount(() => {
    if (timer) {
        window.clearInterval(timer)
    }
})
</script>

<style scoped>
.dashboard {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.summary-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-card {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}

.summary-head {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.summary-head .el-icon {
    border-radius: 8px;
    font-size: 20px;
    height: 38px;
    width: 38px;
}

.summary-title {
    color: #6b7280;
    font-size: 13px;
    margin-top: 16px;
}

.summary-value {
    color: #111827;
    font-size: 24px;
    font-weight: 700;
    line-height: 1.2;
    margin-top: 6px;
    overflow-wrap: anywhere;
}

.summary-desc {
    color: #6b7280;
    font-size: 12px;
    margin-top: 8px;
}

.toolbar {
    align-items: center;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    display: flex;
    justify-content: space-between;
    padding: 14px 16px;
}

.checked-at {
    color: #6b7280;
    font-size: 13px;
}

.quick-card {
    border-radius: 8px;
    height: 100%;
}

.card-header {
    color: #111827;
    font-weight: 700;
}

.quick-list {
    display: grid;
    gap: 10px;
}

.quick-item {
    align-items: center;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    color: inherit;
    display: grid;
    gap: 12px;
    grid-template-columns: 32px 1fr 20px;
    min-height: 64px;
    padding: 10px 12px;
    text-decoration: none;
}

.quick-item:hover {
    background: #f9fafb;
    border-color: #bfdbfe;
}

.quick-item .el-icon {
    color: #2563eb;
    font-size: 20px;
}

.quick-title {
    color: #111827;
    font-size: 14px;
    font-weight: 700;
}

.quick-desc {
    color: #6b7280;
    font-size: 12px;
    margin-top: 3px;
}

.quick-arrow {
    color: #9ca3af !important;
}

.lower-row {
    margin-top: 0;
}

.icon-blue {
    background: #dbeafe;
    color: #1d4ed8;
}

.icon-green {
    background: #dcfce7;
    color: #15803d;
}

.icon-violet {
    background: #ede9fe;
    color: #6d28d9;
}

.icon-slate {
    background: #e5e7eb;
    color: #374151;
}

@media (max-width: 1200px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .toolbar {
        align-items: flex-start;
        flex-direction: column;
        gap: 10px;
    }
}
</style>
