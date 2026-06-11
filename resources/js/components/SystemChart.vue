<template>
    <el-card>
        <template #header>
            System Realtime Monitor
        </template>

        <div ref="chartRef" style="height: 320px;"></div>
    </el-card>
</template>

<script setup lang="ts">
import {ref, onMounted, onBeforeUnmount} from 'vue'
import * as echarts from 'echarts'
import echo from '../utils/echo'

const chartRef = ref<HTMLDivElement>()
let chart: echarts.ECharts | null = null
let channel: any = null

const MAX_POINTS = 30

const cpuData: number[] = []
const memoryData: number[] = []
const timeData: string[] = []

/**
 * 初始化图表
 */
const initChart = () => {

    if (!chartRef.value) return

    chart = echarts.init(chartRef.value)

    chart.setOption({
        tooltip: {trigger: 'axis'},

        legend: {
            data: ['CPU %', 'Memory %']
        },

        xAxis: {
            type: 'category',
            data: timeData,
        },

        yAxis: {
            type: 'value',
            max: 100
        },

        series: [
            {
                name: 'CPU %',
                type: 'line',
                smooth: true,
                data: cpuData
            },
            {
                name: 'Memory %',
                type: 'line',
                smooth: true,
                data: memoryData
            }
        ]
    })
}

/**
 * 更新图表
 */
const updateChart = () => {
    if (!chart) return

    chart.setOption({
        xAxis: {data: timeData},
        series: [
            {data: cpuData},
            {data: memoryData}
        ]
    })
}

/**
 * WebSocket实时监听
 */
const startEcho = () => {

    echo.channel('ops.system.metrics')
        .listen('.metrics.updated', (e: any) => {

            const data = e?.data

            if (!data) {
                console.warn('metrics payload empty', data)
                return
            }

            const now = new Date().toLocaleTimeString()

            cpuData.push((data.cpu ?? 0) * 100)

            const mem =
                data.memory?.total
                    ? ((data.memory.total - data.memory.available) / data.memory.total) * 100
                    : 0

            memoryData.push(Number(mem.toFixed(2)))

            timeData.push(now)

            if (cpuData.length > MAX_POINTS) {
                cpuData.shift()
                memoryData.shift()
                timeData.shift()
            }

            updateChart()
        })
}

onMounted(() => {
    initChart()
    startEcho()
})

onBeforeUnmount(() => {
    if (channel) {
        echo.leaveChannel('ops.system.metrics')
    }

    if (chart) {
        chart.dispose()
        chart = null
    }
})
</script>
