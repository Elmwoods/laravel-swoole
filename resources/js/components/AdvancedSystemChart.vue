<template>
    <el-card>
        <template #header>
            Advanced System Monitor
        </template>

        <div ref="chartRef" style="height: 420px;"></div>
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
const chartRef = ref<HTMLDivElement | null>(null)
let chart: echarts.ECharts | null = null
let channel: any = null

/**
 * config
 */
const MAX_POINTS = 30

/**
 * data
 */
const timeAxis: string[] = []

const load1: number[] = []
const load5: number[] = []
const load15: number[] = []

const memory: number[] = []
const swap: number[] = []

const netRx: number[] = []
const netTx: number[] = []

/**
 * network speed
 */
let lastRx = 0
let lastTx = 0

/**
 * init chart (⚠️ 必须完整结构)
 */
const initChart = () => {
    if (!chartRef.value) return

    chart = echarts.init(chartRef.value)

    chart.setOption({
        tooltip: {
            trigger: 'axis'
        },

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

        xAxis: {
            type: 'category',
            data: timeAxis
        },

        yAxis: [
            {
                type: 'value'
            }
        ],

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
const pushData = (data: any, now: string) => {

    if (!data) return

    /**
     * Load Average
     */
    load1.push(Number(data.load?.[0] ?? 0))
    load5.push(Number(data.load?.[1] ?? 0))
    load15.push(Number(data.load?.[2] ?? 0))

    /**
     * Memory %
     */
    const memoryTotal = Number(data.memory?.total ?? 0)
    const memoryAvailable = Number(data.memory?.available ?? 0)

    const memoryUsedPercent = memoryTotal > 0
        ? ((memoryTotal - memoryAvailable) / memoryTotal * 100)
        : 0

    memory.push(Number(memoryUsedPercent.toFixed(2)))

    /**
     * Swap %
     */
    const swapTotal = Number(data.swap?.total ?? 0)
    const swapFree = Number(data.swap?.free ?? 0)

    const swapUsedPercent = swapTotal > 0
        ? ((swapTotal - swapFree) / swapTotal * 100)
        : 0

    swap.push(Number(swapUsedPercent.toFixed(2)))

    /**
     * Network Speed (MB/s)
     */
    const network = data.network ?? {}

    let totalRx = 0
    let totalTx = 0

    Object.values(network).forEach((item: any) => {

        totalRx += Number(item.rx || 0)
        totalTx += Number(item.tx || 0)
    })

    const rxRate = lastRx > 0
        ? totalRx - lastRx
        : 0

    const txRate = lastTx > 0
        ? totalTx - lastTx
        : 0

    lastRx = totalRx
    lastTx = totalTx

    netRx.push(
        Number((rxRate / 1024 / 1024).toFixed(2))
    )

    netTx.push(
        Number((txRate / 1024 / 1024).toFixed(2))
    )

    /**
     * Time
     */
    timeAxis.push(now)

    /**
     * keep max points
     */
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
    try {
        const res = await axios.get('/api/ops/system/advanced')

        const data = res.data.data
        const now = new Date().toLocaleTimeString()

        pushData(data, now)
        updateChart()

    } catch (err) {
        console.error('init system monitor failed', err)
    }
}

/**
 * websocket
 */
const startEcho = () => {

    if (channel) return

    channel = echo.channel('ops.system.metrics')
        .listen('.metrics.updated', (e: any) => {

            const data = e?.data
            const now = data?.time || new Date().toLocaleTimeString()

            pushData(data, now)
            updateChart()
        })
}

/**
 * mount
 */
onMounted(async () => {

    initChart()

    await initData()

    startEcho()
})

/**
 * cleanup
 */
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
