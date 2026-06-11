<template>
    <div class="redis-chart">

        <el-card>
            <template #header>
                Redis 实时性能图表
            </template>

            <div ref="chartRef" style="height: 400px;"></div>

        </el-card>

    </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from 'axios'
import * as echarts from 'echarts'

const chartRef = ref<HTMLDivElement>()
let chart: echarts.ECharts

/**
 * 初始化图表
 */
const initChart = () => {
    chart = echarts.init(chartRef.value!)

    chart.setOption({
        title: { text: 'Redis OPS / Clients / Memory' },
        tooltip: { trigger: 'axis' },
        legend: {
            data: ['OPS', 'Clients', 'Memory']
        },
        xAxis: {
            type: 'category',
            data: []
        },
        yAxis: {
            type: 'value'
        },
        series: [
            { name: 'OPS', type: 'line', data: [] },
            { name: 'Clients', type: 'line', data: [] },
            { name: 'Memory', type: 'line', data: [] }
        ]
    })
}

/**
 * 更新数据
 */
const fetchData = async () => {
    const res = await axios.get('/api/ops/redis-metrics/chart')
    const data = res.data.data

    const time = data.map((i: any) => i.time)
    const ops = data.map((i: any) => i.ops)
    const clients = data.map((i: any) => i.clients)
    const memory = data.map((i: any) => i.memory)

    chart.setOption({
        xAxis: { data: time },
        series: [
            { name: 'OPS', data: ops },
            { name: 'Clients', data: clients },
            { name: 'Memory', data: memory }
        ]
    })
}

/**
 * 启动实时刷新
 */
onMounted(() => {
    initChart()

    fetchData()

    setInterval(async () => {
        await axios.get('/api/ops/redis-metrics/push')
        fetchData()
    }, 5000)
})
</script>
