<template>
    <section class="docker-dashboard">
        <div class="docker-summary">
            <el-card
                v-for="item in summaryCards"
                :key="item.title"
                shadow="never"
                class="summary-card"
            >
                <div class="summary-title">{{ item.title }}</div>
                <div class="summary-value">{{ item.value }}</div>
                <div class="summary-desc">{{ item.desc }}</div>
            </el-card>
        </div>

        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <span>Docker 容器状态</span>

                    <el-button :icon="Refresh" :loading="loading" @click="loadContainers">
                        刷新
                    </el-button>
                </div>
            </template>

            <el-table :data="containers" border stripe v-loading="loading">
                <el-table-column prop="name" label="容器" min-width="180" show-overflow-tooltip />
                <el-table-column prop="image" label="镜像" min-width="220" show-overflow-tooltip />

                <el-table-column prop="state" label="状态" width="120">
                    <template #default="{ row }">
                        <el-tag :type="row.state === 'running' ? 'success' : 'info'">
                            {{ row.state }}
                        </el-tag>
                    </template>
                </el-table-column>

                <el-table-column prop="status" label="运行信息" min-width="220" show-overflow-tooltip />

                <el-table-column label="操作" width="310" fixed="right">
                    <template #default="{ row }">
                        <el-space>
                            <el-button size="small" :icon="DataLine" @click="openStats(row)">
                                资源
                            </el-button>

                            <el-button size="small" type="warning" :icon="Document" @click="showLogs(row)">
                                日志
                            </el-button>

                            <el-button
                                v-if="row.state === 'running'"
                                size="small"
                                type="danger"
                                :icon="VideoPause"
                                @click="stop(row)"
                            >
                                停止
                            </el-button>

                            <el-button
                                v-else
                                size="small"
                                type="success"
                                :icon="VideoPlay"
                                @click="start(row)"
                            >
                                启动
                            </el-button>

                            <el-button size="small" type="primary" :icon="RefreshRight" @click="restart(row)">
                                重启
                            </el-button>
                        </el-space>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <el-drawer
            v-model="statsVisible"
            title="容器资源实时图表"
            size="720px"
            @closed="stopStatsPolling"
        >
            <div v-if="activeContainer" class="drawer-title">
                <div class="drawer-name">{{ activeContainer.name }}</div>
                <div class="drawer-desc">{{ activeContainer.full_id }}</div>
            </div>

            <div class="stats-grid">
                <el-card shadow="never">
                    <el-statistic title="CPU" :value="latestStats?.cpu_percent ?? 0" suffix="%" />
                </el-card>

                <el-card shadow="never">
                    <el-statistic title="Memory" :value="latestStats?.memory.percent ?? 0" suffix="%" />
                </el-card>

                <el-card shadow="never">
                    <el-statistic title="RX" :value="latestStats?.network.rx_mb ?? 0" suffix="MB" />
                </el-card>

                <el-card shadow="never">
                    <el-statistic title="TX" :value="latestStats?.network.tx_mb ?? 0" suffix="MB" />
                </el-card>
            </div>

            <div ref="statsChartRef" class="stats-chart"></div>
        </el-drawer>

        <DockerLogDialog ref="logDialog" />
    </section>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import * as echarts from 'echarts'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
    DataLine,
    Document,
    Refresh,
    RefreshRight,
    VideoPause,
    VideoPlay,
} from '@element-plus/icons-vue'
import DockerLogDialog from './DockerLogDialog.vue'
import {
    getDockerContainers,
    getDockerStats,
    restartDockerContainer,
    startDockerContainer,
    stopDockerContainer,
    type DockerContainer,
    type DockerStats,
} from '@/api/opsStage2'

const containers = ref<DockerContainer[]>([])
const loading = ref(false)
const summary = ref({
    total: 0,
    running: 0,
    exited: 0,
    unhealthy: 0,
    checked_at: '',
})

const logDialog = ref()
const statsVisible = ref(false)
const activeContainer = ref<DockerContainer | null>(null)
const latestStats = ref<DockerStats | null>(null)
const statsChartRef = ref<HTMLDivElement | null>(null)
let statsChart: echarts.ECharts | null = null
let statsTimer: number | null = null
let listTimer: number | null = null

const chartTime: string[] = []
const cpuData: number[] = []
const memoryData: number[] = []

const summaryCards = computed(() => [
    {
        title: '容器总数',
        value: summary.value.total,
        desc: `最后检查：${summary.value.checked_at || '-'}`,
    },
    {
        title: '运行中',
        value: summary.value.running,
        desc: 'state = running',
    },
    {
        title: '已停止',
        value: summary.value.exited,
        desc: 'state = exited',
    },
    {
        title: '异常健康',
        value: summary.value.unhealthy,
        desc: '包含 unhealthy 状态',
    },
])

/**
 * 根据容器列表在前端生成汇总。
 *
 * 说明：
 * - 这里优先使用一直存在的 /api/ops/docker/containers 接口。
 * - 这样即使运行中的 Laravel 还没清理路由缓存、暂时没有 /summary，也不会导致 Docker 页面 404。
 */
const applyContainers = (items: DockerContainer[]) => {
    const safeItems = Array.isArray(items) ? items : []

    containers.value = safeItems
    summary.value = {
        total: safeItems.length,
        running: safeItems.filter((item) => item.state === 'running').length,
        exited: safeItems.filter((item) => item.state === 'exited').length,
        unhealthy: safeItems.filter((item) => item.status?.toLowerCase().includes('unhealthy')).length,
        checked_at: new Date().toLocaleString(),
    }
}

/**
 * 获取容器列表和汇总状态。
 */
const loadContainers = async () => {
    loading.value = true

    try {
        const res = await getDockerContainers()
        applyContainers(res.data.data)
    } catch {
        containers.value = []
        summary.value = {
            total: 0,
            running: 0,
            exited: 0,
            unhealthy: 0,
            checked_at: '',
        }
        ElMessage.error('Docker 容器状态加载失败')
    } finally {
        loading.value = false
    }
}

/**
 * 初始化容器资源图表。
 */
const initStatsChart = () => {
    if (!statsChartRef.value) return

    statsChart = echarts.init(statsChartRef.value)
    statsChart.setOption({
        tooltip: { trigger: 'axis' },
        legend: { data: ['CPU %', 'Memory %'] },
        xAxis: { type: 'category', data: chartTime },
        yAxis: { type: 'value', max: 100 },
        series: [
            { name: 'CPU %', type: 'line', smooth: true, data: cpuData },
            { name: 'Memory %', type: 'line', smooth: true, data: memoryData },
        ],
    })
}

/**
 * 拉取指定容器资源快照，并推入趋势图。
 */
const loadStats = async () => {
    if (!activeContainer.value) return

    try {
        const res = await getDockerStats(activeContainer.value.full_id)
        latestStats.value = res.data.data

        if (res.data.data.available === false) {
            ElMessage.warning(res.data.data.error || 'Docker 资源数据暂不可用')
        }

        chartTime.push(new Date().toLocaleTimeString())
        cpuData.push(res.data.data.cpu_percent)
        memoryData.push(res.data.data.memory.percent)

        if (chartTime.length > 30) {
            chartTime.shift()
            cpuData.shift()
            memoryData.shift()
        }

        statsChart?.setOption({
            xAxis: { data: chartTime },
            series: [
                { data: cpuData },
                { data: memoryData },
            ],
        })
    } catch {
        ElMessage.error('Docker 资源数据加载失败')
        stopStatsPolling()
    }
}

/**
 * 打开资源抽屉并开始轮询。
 */
const openStats = async (row: DockerContainer) => {
    activeContainer.value = row
    statsVisible.value = true
    latestStats.value = null
    chartTime.splice(0)
    cpuData.splice(0)
    memoryData.splice(0)

    await nextTick()
    initStatsChart()
    await loadStats()
    statsTimer = window.setInterval(loadStats, 3000)
}

/**
 * 停止资源轮询并释放图表。
 */
const stopStatsPolling = () => {
    if (statsTimer) {
        window.clearInterval(statsTimer)
        statsTimer = null
    }

    statsChart?.dispose()
    statsChart = null
}

const showLogs = (row: DockerContainer) => {
    logDialog.value.open(row.full_id)
}

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

const restart = async (row: DockerContainer) => {
    const confirmText = await askConfirm(`重启容器 ${row.name}`)
    if (!confirmText) return

    await restartDockerContainer(row.full_id, confirmText)
    ElMessage.success('容器重启指令已发送')
    await loadContainers()
}

const start = async (row: DockerContainer) => {
    await startDockerContainer(row.full_id)
    ElMessage.success('容器启动指令已发送')
    await loadContainers()
}

const stop = async (row: DockerContainer) => {
    const confirmText = await askConfirm(`停止容器 ${row.name}`)
    if (!confirmText) return

    await stopDockerContainer(row.full_id, confirmText)
    ElMessage.success('容器停止指令已发送')
    await loadContainers()
}

onMounted(() => {
    loadContainers()
    listTimer = window.setInterval(loadContainers, 10000)
})

onBeforeUnmount(() => {
    if (listTimer) {
        window.clearInterval(listTimer)
    }

    stopStatsPolling()
})
</script>

<style scoped>
.docker-dashboard {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.docker-summary {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-card {
    border-radius: 8px;
}

.summary-title {
    color: #6b7280;
    font-size: 13px;
}

.summary-value {
    color: #111827;
    font-size: 28px;
    font-weight: 700;
    margin-top: 8px;
}

.summary-desc {
    color: #6b7280;
    font-size: 12px;
    margin-top: 6px;
}

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.drawer-title {
    margin-bottom: 16px;
}

.drawer-name {
    color: #111827;
    font-size: 18px;
    font-weight: 700;
}

.drawer-desc {
    color: #6b7280;
    font-size: 12px;
    margin-top: 4px;
    overflow-wrap: anywhere;
}

.stats-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin-bottom: 18px;
}

.stats-chart {
    height: 360px;
}

@media (max-width: 1100px) {
    .docker-summary,
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .docker-summary,
    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>
