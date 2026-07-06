<template>
    <section class="network-monitor">
        <div class="summary-grid">
            <el-card shadow="never">
                <el-statistic title="总下载" :value="summary.summary.rx_kb_s" suffix="KB/s" />
            </el-card>

            <el-card shadow="never">
                <el-statistic title="总上传" :value="summary.summary.tx_kb_s" suffix="KB/s" />
            </el-card>

            <el-card shadow="never">
                <el-statistic title="下载 MB/s" :value="summary.summary.rx_mb_s" suffix="MB/s" />
            </el-card>

            <el-card shadow="never">
                <el-statistic title="上传 MB/s" :value="summary.summary.tx_mb_s" suffix="MB/s" />
            </el-card>
        </div>

        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <span>网络吞吐实时图表</span>

                    <el-button :icon="Refresh" :loading="loading" @click="fetchData">
                        刷新
                    </el-button>
                </div>
            </template>

            <div ref="chartRef" class="network-chart"></div>
        </el-card>

        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <span>网卡明细</span>
                    <span class="time">更新时间：{{ timestamp || '-' }}</span>
                </div>
            </template>

            <el-table :data="networks" border stripe v-loading="loading">
                <el-table-column prop="iface" label="网卡" width="150" />
                <el-table-column prop="rx_kb_s" label="下载 KB/s" />
                <el-table-column prop="tx_kb_s" label="上传 KB/s" />
                <el-table-column prop="rx_mb_s" label="下载 MB/s" />
                <el-table-column prop="tx_mb_s" label="上传 MB/s" />
                <el-table-column prop="rx_packets" label="RX 包" />
                <el-table-column prop="tx_packets" label="TX 包" />

                <el-table-column label="状态" width="110">
                    <template #default="{ row }">
                        <el-tag :type="row.rx_kb_s > 0 || row.tx_kb_s > 0 ? 'success' : 'info'">
                            {{ row.rx_kb_s > 0 || row.tx_kb_s > 0 ? 'Active' : 'Idle' }}
                        </el-tag>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>
    </section>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import * as echarts from 'echarts'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import echo from '@/utils/echo'
import { getNetworkSummary, type NetworkSummary } from '@/api/opsStage2'

interface NetworkItem {
    iface: string
    rx_kb_s: number
    tx_kb_s: number
    rx_mb_s: number
    tx_mb_s: number
    rx_packets: number
    tx_packets: number
}

const loading = ref(false)
const networks = ref<NetworkItem[]>([])
const timestamp = ref('')
const chartRef = ref<HTMLDivElement | null>(null)
let chart: echarts.ECharts | null = null
let channel: any = null
let fallbackTimer: number | null = null
let staleCheckTimer: number | null = null
let lastRealtimeAt = 0

const summary = ref<NetworkSummary>({
    status: 'warming',
    timestamp: 0,
    summary: {
        rx_kb_s: 0,
        tx_kb_s: 0,
        rx_mb_s: 0,
        tx_mb_s: 0,
    },
    interfaces: {},
})

const timeAxis: string[] = []
const rxData: number[] = []
const txData: number[] = []

/**
 * 初始化网络吞吐图表。
 */
const initChart = () => {
    if (!chartRef.value) return

    chart = echarts.init(chartRef.value)
    chart.setOption({
        tooltip: { trigger: 'axis' },
        legend: { data: ['RX KB/s', 'TX KB/s'] },
        xAxis: { type: 'category', data: timeAxis },
        yAxis: { type: 'value' },
        series: [
            { name: 'RX KB/s', type: 'line', smooth: true, data: rxData },
            { name: 'TX KB/s', type: 'line', smooth: true, data: txData },
        ],
    })
}

/**
 * 将后端对象转换为表格数组。
 */
const normalizeInterfaces = (data: NetworkSummary): NetworkItem[] => {
    return Object.entries(data.interfaces ?? {})
        .map(([iface, item]) => ({
            iface,
            rx_kb_s: Number(item.rx_kb_s ?? 0),
            tx_kb_s: Number(item.tx_kb_s ?? 0),
            rx_mb_s: Number(item.rx_mb_s ?? 0),
            tx_mb_s: Number(item.tx_mb_s ?? 0),
            rx_packets: Number(item.rx_packets ?? 0),
            tx_packets: Number(item.tx_packets ?? 0),
        }))
        .sort((a, b) => (b.rx_kb_s + b.tx_kb_s) - (a.rx_kb_s + a.tx_kb_s))
}

/**
 * 推送一帧网络数据到图表和表格。
 */
const applyNetworkData = (data: NetworkSummary) => {
    if (!data?.summary) {
        return
    }

    summary.value = data
    networks.value = normalizeInterfaces(data)
    timestamp.value = data.timestamp
        ? new Date(data.timestamp * 1000).toLocaleString()
        : new Date().toLocaleString()

    timeAxis.push(new Date().toLocaleTimeString())
    rxData.push(data.summary.rx_kb_s)
    txData.push(data.summary.tx_kb_s)

    if (timeAxis.length > 30) {
        timeAxis.shift()
        rxData.shift()
        txData.shift()
    }

    chart?.setOption({
        xAxis: { data: timeAxis },
        series: [
            { data: rxData },
            { data: txData },
        ],
    })
}

/**
 * HTTP 获取网络状态。
 */
const fetchData = async (silent = false) => {
    if (!silent) {
        loading.value = true
    }

    try {
        const res = await getNetworkSummary()
        applyNetworkData(res.data.data)
    } catch {
        if (!silent) {
            ElMessage.error('网络流量数据加载失败')
        }
    } finally {
        if (!silent) {
            loading.value = false
        }
    }
}

/**
 * 当 WebSocket 长时间没有数据时，启用 HTTP 兜底轮询。
 */
const ensureFallbackPolling = () => {
    if (Date.now() - lastRealtimeAt < 10000) {
        if (fallbackTimer) {
            window.clearInterval(fallbackTimer)
            fallbackTimer = null
        }

        return
    }

    if (!fallbackTimer) {
        fallbackTimer = window.setInterval(() => fetchData(true), 5000)
    }
}

onMounted(() => {
    initChart()
    fetchData()

    try {
        channel = echo
            .channel('ops.system.metrics')
            .listen('.network.updated', (event: NetworkSummary) => {
                if (event?.summary) {
                    lastRealtimeAt = Date.now()
                    applyNetworkData(event)
                }
            })
    } catch {
        ensureFallbackPolling()
    }

    staleCheckTimer = window.setInterval(ensureFallbackPolling, 5000)
    window.addEventListener('resize', resizeChart)
})

onBeforeUnmount(() => {
    if (channel) {
        echo.leaveChannel('ops.system.metrics')
        channel = null
    }

    if (fallbackTimer) {
        window.clearInterval(fallbackTimer)
    }

    if (staleCheckTimer) {
        window.clearInterval(staleCheckTimer)
    }

    window.removeEventListener('resize', resizeChart)
    chart?.dispose()
    chart = null
})

/**
 * 容器尺寸变化时重绘图表。
 */
const resizeChart = () => {
    chart?.resize()
}
</script>

<style scoped>
.network-monitor {
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

.network-chart {
    height: 360px;
}

.time {
    color: #6b7280;
    font-size: 13px;
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
