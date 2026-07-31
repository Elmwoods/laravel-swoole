<template>
    <section class="dashboard">
        <!-- 顶部核心状态卡片区：遍历 summaryCards 渲染 Octane/Redis/MySQL/内存/告警 概览 -->
        <div class="summary-grid">
            <el-card
                v-for="item in summaryCards"
                :key="item.title"
                shadow="never"
                class="summary-card"
            >
                <!-- 卡片头部：左侧带背景色的图标，右侧状态标签（正常/异常/实时等） -->
                <div class="summary-head">
                    <el-icon :class="item.iconClass">
                        <component :is="item.icon" />
                    </el-icon>

                    <el-tag :type="item.tagType" effect="plain">
                        {{ item.status }}
                    </el-tag>
                </div>

                <!-- 卡片正文：标题 / 主数值 / 补充描述 -->
                <div class="summary-title">{{ item.title }}</div>
                <div class="summary-value">{{ item.value }}</div>
                <div class="summary-desc">{{ item.desc }}</div>
            </el-card>
        </div>

        <!-- 工具栏：手动刷新按钮 + 高风险的 Reload Octane 按钮 + 最后刷新时间 -->
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

        <!-- 主图表行：左侧高级系统图表，右侧服务快捷入口卡片（大屏 16:8，窄屏堆叠） -->
        <el-row :gutter="20">
            <el-col :xs="24" :xl="16">
                <AdvancedSystemChart />
            </el-col>

            <el-col :xs="24" :xl="8">
                <el-card shadow="never" class="quick-card">
                    <template #header>
                        <div class="card-header">服务快捷入口</div>
                    </template>

                    <!-- 快捷入口列表：遍历 services 生成跳转到各运维子页面的链接 -->
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

        <!-- 底部行：左侧系统信息卡片，右侧系统图表（大屏 10:14 分栏，窄屏堆叠） -->
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
    Warning,
} from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
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
    alerts?: {
        open_total?: number
        critical?: number
        warning?: number
        info?: number
    }
}

// 首页汇总数据（后端 /dashboard 返回），初始为 null 以区分「未加载」与「空数据」
const data = ref<DashboardData | null>(null)
// 列表刷新中标志：驱动刷新按钮 loading 态
const loading = ref(false)
// 高风险操作（Reload Octane）执行中标志：与列表刷新分开，避免互相影响按钮态
const actionLoading = ref(false)
// 最后一次成功刷新的本地时间字符串，展示在工具栏右侧
const checkedAt = ref('')
// 轮询定时器句柄：挂载时启动、卸载时清理，防止组件销毁后仍在请求
let timer: number | null = null

// 根据连通性布尔值映射为中文状态文案，供状态标签复用
const serviceStatus = (online?: boolean) => online ? '正常' : '异常'
// 根据连通性布尔值映射为 el-tag 的类型（绿色成功 / 红色危险）
const serviceTag = (online?: boolean) => online ? 'success' : 'danger'

/**
 * 首页核心状态卡片。
 * 为什么：用 computed 依赖 data，data 每次轮询更新后卡片自动重算；
 * 全程用 ?? 兜底默认值，保证 data 尚未加载（null）时也能安全渲染。
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
    {
        title: 'Alerts',
        value: `${data.value?.alerts?.open_total ?? 0} Open`,
        desc: `Critical：${data.value?.alerts?.critical ?? 0} / Warning：${data.value?.alerts?.warning ?? 0}`,
        status: (data.value?.alerts?.open_total ?? 0) > 0 ? '待处理' : '正常',
        // 标签颜色按严重度分级：有 critical 用红色，其余有未处理告警用橙色，全部处理完用绿色，
        // 以便一眼判断是否需要立即介入
        tagType: (data.value?.alerts?.critical ?? 0) > 0
            ? 'danger'
            : ((data.value?.alerts?.open_total ?? 0) > 0 ? 'warning' : 'success'),
        icon: Warning,
        iconClass: (data.value?.alerts?.open_total ?? 0) > 0 ? 'icon-orange' : 'icon-green',
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
    {
        title: 'Alert Center',
        desc: '查看未处理告警和通知通道状态',
        path: '/admin/ops/alerts',
        icon: Warning,
    },
]

/**
 * 获取首页汇总数据。
 * 为什么：既在挂载时调用，也被 5 秒轮询和 reload 后复用；失败只提示不清空旧数据，
 * 避免网络抖动时页面瞬间空白。
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
 * 高风险控制确认。
 * 为什么：Reload Octane 会影响线上进程，强制用户手动输入 CONFIRM 短语，
 * 避免误点按钮直接触发；取消或不匹配时返回 null，交由调用方中止。
 */
const askConfirm = async (action: string) => {
    try {
        const { value } = await ElMessageBox.prompt(
            `请输入 CONFIRM 确认${action}`,
            '高风险操作确认',
            {
                confirmButtonText: '确认执行',
                cancelButtonText: '取消',
                inputPattern: /^CONFIRM$/,
                inputErrorMessage: '确认短语必须为 CONFIRM',
                type: 'warning',
            },
        )

        return value
    } catch {
        return null
    }
}

/**
 * 平滑重载 Octane Worker。
 * 为什么：先经二次确认拿到 CONFIRM 短语再调用后端，成功后立即刷新一次数据，
 * 让用户看到 reload 后的最新进程状态。
 */
const reload = async () => {
    const confirmText = await askConfirm('Reload Octane')
    // 用户取消或未通过确认，直接中止，不发起请求
    if (!confirmText) return

    actionLoading.value = true

    try {
        await reloadOctane(confirmText)
        ElMessage.success('Octane Reload 已发送')
        await fetch()
    } finally {
        actionLoading.value = false
    }
}

// 挂载后立即拉取一次并开启 5 秒轮询，保证首页监控数据接近实时
onMounted(() => {
    fetch()
    timer = window.setInterval(fetch, 5000)
})

// 卸载前清除轮询定时器，避免离开页面后仍在后台发请求造成泄漏
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
    grid-template-columns: repeat(5, minmax(0, 1fr));
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

.icon-orange {
    background: #ffedd5;
    color: #c2410c;
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
