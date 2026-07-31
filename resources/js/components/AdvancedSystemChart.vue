<template>
    <!-- 系统趋势卡片：多指标（负载/内存/Swap/网络）折线，loading 时遮罩 -->
    <el-card v-loading="loading" shadow="never" class="advanced-system-chart">
        <template #header>
            <div class="card-header">
                <span>系统趋势</span>
                <!-- 重试按钮重新拉取一次 HTTP 数据 -->
                <el-button size="small" text @click="initData">重试</el-button>
            </div>
        </template>

        <!-- 接口异常时展示告警条 -->
        <el-alert
            v-if="errorMessage"
            :title="errorMessage"
            type="warning"
            show-icon
            :closable="false"
            class="state-alert"
        />

        <!-- 非加载、无错误且尚无任何数据点时展示空状态占位 -->
        <el-empty v-if="!loading && !errorMessage && timeAxis.length === 0" description="暂无系统趋势数据" />
        <!-- ECharts 挂载容器 -->
        <div ref="chartRef" class="chart"></div>
    </el-card>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import axios from '../utils/axios'
import * as echarts from 'echarts'
import echo from "../utils/echo"

/**
 * chart
 */
// 图表 DOM 容器引用
const chartRef = ref<HTMLDivElement | null>(null)
// ECharts 实例，卸载时 dispose
let chart: echarts.ECharts | null = null
// Echo 频道句柄，卸载时退订
let channel: any = null
// 加载态，驱动 el-card 的 v-loading
const loading = ref(false)
// 接口错误提示文案
const errorMessage = ref('')

/**
 * config
 */
// 滚动窗口最大点数，超出后从头部移除
const MAX_POINTS = 30

/**
 * data
 */
// 横轴时间刻度；下方各指标数组与其同下标一一对应
const timeAxis: string[] = []

// 系统负载：1 / 5 / 15 分钟平均值
const load1: number[] = []
const load5: number[] = []
const load15: number[] = []

// 内存使用率、Swap 使用率（百分比）
const memory: number[] = []
const swap: number[] = []

// 网络下行 / 上行速率（MB/s）
const netRx: number[] = []
const netTx: number[] = []

/**
 * network speed
 */
// 上一次采样的累计收发字节，用于差分计算瞬时速率
let lastRx = 0
let lastTx = 0

/**
 * init chart (⚠️ 必须完整结构)
 */
const initChart = () => {
    if (!chartRef.value) return

    chart = echarts.init(chartRef.value)

    // 一次性声明完整 option（图例 + 坐标轴 + 7 条 series 结构）；
    // 后续 updateChart 只做增量数据替换，避免重复构造结构导致样式丢失
    chart.setOption({
        // 沿轴触发提示框，悬停可对比同一时刻的全部指标
        tooltip: {
            trigger: 'axis'
        },

        // 图例名称必须与下方 series.name 严格一致才能联动显隐
        legend: {
            top: 10,
            data: [
                'Load 1m',
                'Load 5m',
                'Load 15m',
                'Memory %',
                'Swap %',
                'RX MB/s',
                'TX MB/s'
            ]
        },

        // X 轴时间类目，绑定 timeAxis
        xAxis: {
            type: 'category',
            data: timeAxis
        },

        // 单一数值 Y 轴：负载与百分比、网络速率共用（量纲不同，仅看趋势）
        yAxis: [
            {
                type: 'value'
            }
        ],

        // 7 条平滑折线，与上面的图例顺序、名称一一对应
        series: [
            { name: 'Load 1m', type: 'line', smooth: true, data: load1 },
            { name: 'Load 5m', type: 'line', smooth: true, data: load5 },
            { name: 'Load 15m', type: 'line', smooth: true, data: load15 },
            { name: 'Memory %', type: 'line', smooth: true, data: memory },
            { name: 'Swap %', type: 'line', smooth: true, data: swap },
            { name: 'RX MB/s', type: 'line', smooth: true, data: netRx },
            { name: 'TX MB/s', type: 'line', smooth: true, data: netTx }
        ]
    })
}

/**
 * safe push data
 */
// const pushData = (data: any, now: string) => {
//
//     if (!data) return
//
//     load1.push(data.load?.[0] ?? 0)
//     load5.push(data.load?.[1] ?? 0)
//     load15.push(data.load?.[2] ?? 0)
//
//     memory.push(data.memory?.available ?? 0)
//     swap.push(data.swap?.free ?? 0)
//
//     // const net = data.network ? Object.values(data.network)[0] as any : null
//     //
//     // netRx.push(Number(net?.rx ?? 0))
//     // netTx.push(Number(net?.tx ?? 0))
//     const network = data.network ?? {}
//
//     let totalRx = 0
//     let totalTx = 0
//
//     Object.values(network).forEach((item: any) => {
//         totalRx += Number(item.rx || 0)
//         totalTx += Number(item.tx || 0)
//     })
//
//     netRx.push(totalRx)
//     netTx.push(totalTx)
//
//     timeAxis.push(now)
//
//     if (timeAxis.length > MAX_POINTS) {
//         timeAxis.shift()
//
//         load1.shift()
//         load5.shift()
//         load15.shift()
//         memory.shift()
//         swap.shift()
//         netRx.shift()
//         netTx.shift()
//     }
// }
// 将单次采样的原始数据归一化后追加到各指标数组，并维护滚动窗口
// 为什么：HTTP 首屏与 WebSocket 推送两条来源共用此逻辑，字段结构可能不同，故做兼容
const pushData = (data: any, now: string) => {

    if (!data) return

    // 负载字段兼容两种结构：数组 [1m,5m,15m] 或对象 {1min,5min,15min}
    const load = Array.isArray(data.load)
        ? {
            one: data.load[0],
            five: data.load[1],
            fifteen: data.load[2],
        }
        : {
            one: data.load?.['1min'],
            five: data.load?.['5min'],
            fifteen: data.load?.['15min'],
        }

    /**
     * Load Average
     */
    // 追加三档负载均值，缺失兜底为 0
    load1.push(Number(load.one ?? 0))
    load5.push(Number(load.five ?? 0))
    load15.push(Number(load.fifteen ?? 0))

    /**
     * Memory %
     */
    // 兼容 total/total_mb 等字段名，计算内存使用率百分比（除零兜底 0）
    const memoryTotal = Number(data.memory?.total ?? data.memory?.total_mb ?? 0)
    const memoryAvailable = Number(data.memory?.available ?? data.memory?.available_mb ?? 0)

    const memoryUsedPercent = memoryTotal > 0
        ? ((memoryTotal - memoryAvailable) / memoryTotal * 100)
        : 0

    memory.push(Number(memoryUsedPercent.toFixed(2)))

    /**
     * Swap %
     */
    // 同上，计算 Swap 使用率百分比
    const swapTotal = Number(data.swap?.total ?? data.swap?.total_mb ?? 0)
    const swapFree = Number(data.swap?.free ?? data.swap?.free_mb ?? 0)

    const swapUsedPercent = swapTotal > 0
        ? ((swapTotal - swapFree) / swapTotal * 100)
        : 0

    swap.push(Number(swapUsedPercent.toFixed(2)))

    /**
     * Network Speed (MB/s)
     */
    // 累加所有网卡的收发字节总量（兼容 rx/rx_bytes 命名）
    const network = data.network ?? {}

    let totalRx = 0
    let totalTx = 0

    Object.values(network).forEach((item: any) => {

        totalRx += Number(item.rx || item.rx_bytes || 0)
        totalTx += Number(item.tx || item.tx_bytes || 0)
    })

    // 用与上次采样的字节差得到瞬时速率；首次（last 为 0）无基准，速率记 0
    const rxRate = lastRx > 0
        ? totalRx - lastRx
        : 0

    const txRate = lastTx > 0
        ? totalTx - lastTx
        : 0

    // 记录本次累计值，作为下次差分的基准
    lastRx = totalRx
    lastTx = totalTx

    // 字节差换算为 MB/s 后追加
    netRx.push(
        Number((rxRate / 1024 / 1024).toFixed(2))
    )

    netTx.push(
        Number((txRate / 1024 / 1024).toFixed(2))
    )

    /**
     * Time
     */
    // 追加本数据点对应的时间刻度
    timeAxis.push(now)

    /**
     * keep max points
     */
    // 超出窗口上限时，所有数组同步移除最旧点，保证长度与下标对齐
    if (timeAxis.length > MAX_POINTS) {

        timeAxis.shift()

        load1.shift()
        load5.shift()
        load15.shift()

        memory.shift()
        swap.shift()

        netRx.shift()
        netTx.shift()
    }
}

/**
 * update chart (⚠️ 不使用 notMerge)
 */
const updateChart = () => {
    if (!chart) return

    // 仅传入变化的横轴与各 series 数据；默认 merge，保留 initChart 声明的名称与样式
    // series 顺序必须与 initChart 完全一致，否则数据会错位到别的曲线
    chart.setOption({
        xAxis: {
            data: timeAxis
        },
        series: [
            { data: load1 },
            { data: load5 },
            { data: load15 },
            { data: memory },
            { data: swap },
            { data: netRx },
            { data: netTx }
        ]
    })
}

/**
 * init http data
 */
const initData = async () => {
    // 进入加载态并清空错误
    loading.value = true
    errorMessage.value = ''

    try {
        // 首屏拉取一次高级系统指标，作为图表初始点；后续增量由 WebSocket 推送
        const res = await axios.get('/api/ops/system/advanced')

        const data = res.data.data
        const now = new Date().toLocaleTimeString()

        pushData(data, now)
        updateChart()

    } catch (err) {
        errorMessage.value = '系统趋势加载失败，请稍后重试。'
    } finally {
        loading.value = false
    }
}

/**
 * websocket
 */
const startEcho = () => {

    // 已订阅则跳过，避免重复监听
    if (channel) return

    // 订阅系统指标频道，每次推送追加一个数据点并刷新图表
    channel = echo.channel('ops.system.metrics')
        .listen('.metrics.updated', (e: any) => {

            const data = e?.data
            // 优先使用后端时间戳，缺失时回退到浏览器本地时间
            const now = data?.time || new Date().toLocaleTimeString()

            pushData(data, now)
            updateChart()
        })
}

/**
 * mount
 */
// 挂载顺序：先建图表结构 → 拉首屏数据 → 再开实时订阅
onMounted(async () => {

    initChart()

    await initData()

    startEcho()
})

/**
 * cleanup
 */
// 卸载：退订频道并销毁 ECharts 实例，防止内存泄漏
onBeforeUnmount(() => {

    if (channel) {
        echo.leaveChannel('ops.system.metrics')
        channel = null
    }

    if (chart) {
        chart.dispose()
        chart = null
    }
})
</script>

<style scoped>
.advanced-system-chart {
    border-radius: 8px;
}

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.state-alert {
    margin-bottom: 14px;
}

.chart {
    height: 420px;
    min-height: 320px;
}
</style>
