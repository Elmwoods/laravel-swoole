<template>
    <div class="redis-chart">

        <el-card v-loading="loading" shadow="never">
            <template #header>
                <div class="card-header">
                    <span>Redis 实时性能图表</span>
                    <el-button size="small" :loading="loading" @click="refreshNow">
                        刷新
                    </el-button>
                </div>
            </template>

            <el-alert
                v-if="errorMessage"
                :title="errorMessage"
                type="warning"
                show-icon
                :closable="false"
                class="state-alert"
            />

            <el-empty
                v-if="!loading && !errorMessage && isEmpty"
                description="暂无 Redis 性能采样数据"
            />

            <div ref="chartRef" class="chart"></div>

        </el-card>

    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import * as echarts from 'echarts'
import request from '@/utils/request'

const chartRef = ref<HTMLDivElement>()
const loading = ref(false)
const errorMessage = ref('')
const sampleCount = ref(0)
let chart: echarts.ECharts | null = null
let refreshTimer: number | null = null

const isEmpty = computed(() => sampleCount.value === 0)

/**
 * 初始化图表
 */
const initChart = () => {
    if (!chartRef.value) return

    chart = echarts.init(chartRef.value)

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
    const res = await request.get('/api/ops/redis-metrics/chart')
    const data = res.data.data
    const rows = Array.isArray(data) ? data : []

    sampleCount.value = rows.length

    const time = rows.map((i: any) => i.time)
    const ops = rows.map((i: any) => i.ops)
    const clients = rows.map((i: any) => i.clients)
    const memory = rows.map((i: any) => i.memory)

    chart?.setOption({
        xAxis: { data: time },
        series: [
            { name: 'OPS', data: ops },
            { name: 'Clients', data: clients },
            { name: 'Memory', data: memory }
        ]
    })
}

const refreshNow = async () => {
    if (loading.value) return

    loading.value = true
    errorMessage.value = ''

    try {
        await request.get('/api/ops/redis-metrics/push')
        await fetchData()
    } catch {
        errorMessage.value = 'Redis 性能图表加载失败，请稍后重试。'
        sampleCount.value = 0
    } finally {
        loading.value = false
    }
}

/**
 * 启动实时刷新
 */
onMounted(() => {
    initChart()
    refreshNow()

    refreshTimer = window.setInterval(refreshNow, 5000)
})

onBeforeUnmount(() => {
    if (refreshTimer) {
        window.clearInterval(refreshTimer)
        refreshTimer = null
    }

    chart?.dispose()
    chart = null
})
</script>

<style scoped>
.redis-chart {
    min-width: 0;
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
    height: 400px;
    min-height: 320px;
}
</style>
