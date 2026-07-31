<template>
    <!-- 系统实时监控卡片：仅展示 CPU 与内存两条实时曲线 -->
    <el-card>
        <template #header>
            System Realtime Monitor
        </template>

        <!-- ECharts 挂载容器，通过 chartRef 引用并在 initChart 中初始化 -->
        <div ref="chartRef" style="height: 320px;"></div>
    </el-card>
</template>

<script setup lang="ts">
import {ref, onMounted, onBeforeUnmount} from 'vue'
import * as echarts from 'echarts'
import echo from '../utils/echo'

// 图表 DOM 容器引用
const chartRef = ref<HTMLDivElement>()
// ECharts 实例，卸载时需 dispose
let chart: echarts.ECharts | null = null
// Echo 频道句柄，卸载时退订
let channel: any = null

// 曲线保留的最大数据点数量，超过后从头部移除，形成滚动窗口
const MAX_POINTS = 30

// 三条平行数组分别缓存 CPU 值、内存值、时间刻度（同下标一一对应）
const cpuData: number[] = []
const memoryData: number[] = []
const timeData: string[] = []

/**
 * 初始化图表
 */
const initChart = () => {

    // 容器未就绪则不初始化
    if (!chartRef.value) return

    chart = echarts.init(chartRef.value)

    // 构建完整 option：一次性声明坐标轴、图例与两条 series 结构，
    // 后续 updateChart 只做增量数据替换，不再重复声明结构
    chart.setOption({
        // 沿坐标轴触发提示框，鼠标悬停可对比同一时刻两条曲线
        tooltip: {trigger: 'axis'},

        // 图例名称需与下方 series.name 保持一致才能联动显隐
        legend: {
            data: ['CPU %', 'Memory %']
        },

        // X 轴为时间类目，数据引用 timeData（push/shift 后靠 updateChart 刷新）
        xAxis: {
            type: 'category',
            data: timeData,
        },

        // Y 轴为百分比，固定上限 100，避免曲线随数据抖动缩放
        yAxis: {
            type: 'value',
            max: 100
        },

        // 两条平滑折线，分别绑定 CPU 与内存数据数组
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

    // 仅传入变化的数据部分，ECharts 默认 merge，保留 initChart 声明的样式与结构
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

    // 订阅系统指标频道，每次广播追加一个数据点并刷新图表
    echo.channel('ops.system.metrics')
        .listen('.metrics.updated', (e: any) => {

            const data = e?.data

            // 空负载时告警并跳过，避免 push 无效数据
            if (!data) {
                console.warn('metrics payload empty', data)
                return
            }

            // 以浏览器本地时间作为该数据点的横轴刻度
            const now = new Date().toLocaleTimeString()

            // 后端 cpu 为 0~1 比例，乘 100 转百分比
            cpuData.push((data.cpu ?? 0) * 100)

            // 内存使用率 =（总量 - 可用）/ 总量 * 100；无 total 时兜底为 0
            const mem =
                data.memory?.total
                    ? ((data.memory.total - data.memory.available) / data.memory.total) * 100
                    : 0

            memoryData.push(Number(mem.toFixed(2)))

            timeData.push(now)

            // 超出窗口上限则三个数组同步移除最旧点，保持长度一致
            if (cpuData.length > MAX_POINTS) {
                cpuData.shift()
                memoryData.shift()
                timeData.shift()
            }

            updateChart()
        })
}

// 挂载：先建图表结构，再开启实时数据订阅
onMounted(() => {
    initChart()
    startEcho()
})

// 卸载：退订频道并销毁 ECharts 实例，释放资源
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
