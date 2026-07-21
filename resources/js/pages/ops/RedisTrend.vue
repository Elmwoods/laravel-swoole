<template>
    <div class="redis-trend">
        <el-card v-loading="loading" shadow="never">
            <template #header>
                <div class="card-header">
                    <div>
                        <div class="title">Redis 指标趋势</div>
                        <div class="subtitle">近 {{ days }} 天每日 OPS / 连接数 / 内存 / 命中率平均值</div>
                    </div>

                    <div class="controls">
                        <el-select v-model="days" size="small" class="days-select" @change="loadTrend">
                            <el-option :value="7" label="近 7 天" />
                            <el-option :value="14" label="近 14 天" />
                            <el-option :value="30" label="近 30 天" />
                        </el-select>
                        <el-button size="small" :loading="loading" :icon="Refresh" @click="loadTrend">刷新</el-button>
                    </div>
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

            <el-empty v-if="!loading && !errorMessage && isEmpty" description="暂无 Redis 指标历史，采集任务运行后将逐步生成" />

            <div v-show="!isEmpty" ref="chartRef" class="chart"></div>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import * as echarts from 'echarts'
import { Refresh } from '@element-plus/icons-vue'
import { getRedisMetricsTrend } from '@/api/opsRedisTrend'

const chartRef = ref<HTMLDivElement>()
const days = ref(14)
const loading = ref(false)
const errorMessage = ref('')
const isEmpty = ref(false)
let chart: echarts.ECharts | null = null

const loadTrend = async () => {
    loading.value = true
    errorMessage.value = ''

    try {
        const res = await getRedisMetricsTrend(days.value)
        const buckets = res.data.data.buckets
        isEmpty.value = buckets.length === 0

        if (!chart && chartRef.value) {
            chart = echarts.init(chartRef.value)
        }

        chart?.setOption({
            tooltip: { trigger: 'axis' },
            legend: { top: 8, left: 'center' },
            grid: { top: 44, left: 8, right: 8, bottom: 8, containLabel: true },
            xAxis: { type: 'category', boundaryGap: false, data: buckets.map(bucket => bucket.date) },
            yAxis: [
                { type: 'value', name: '计数' },
                { type: 'value', name: '%', min: 0, max: 100 },
            ],
            series: [
                { name: 'OPS', type: 'line', smooth: true, yAxisIndex: 0, itemStyle: { color: '#2563eb' }, data: buckets.map(bucket => bucket.ops) },
                { name: '连接数', type: 'line', smooth: true, yAxisIndex: 0, itemStyle: { color: '#7c3aed' }, data: buckets.map(bucket => bucket.clients) },
                { name: '内存 MB', type: 'line', smooth: true, yAxisIndex: 0, itemStyle: { color: '#16a34a' }, data: buckets.map(bucket => bucket.memory_mb) },
                { name: '命中率 %', type: 'line', smooth: true, yAxisIndex: 1, itemStyle: { color: '#d97706' }, data: buckets.map(bucket => bucket.hit_rate) },
            ],
        })

        chart?.resize()
    } catch {
        errorMessage.value = 'Redis 指标趋势加载失败，请稍后重试。'
        isEmpty.value = true
    } finally {
        loading.value = false
    }
}

const handleResize = () => chart?.resize()

onMounted(async () => {
    await loadTrend()
    window.addEventListener('resize', handleResize)
})

onBeforeUnmount(() => {
    window.removeEventListener('resize', handleResize)
    chart?.dispose()
    chart = null
})
</script>

<style scoped>
.redis-trend {
    min-width: 0;
}

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.title {
    color: #102033;
    font-size: 16px;
    font-weight: 600;
}

.subtitle {
    color: #64748b;
    font-size: 12px;
    margin-top: 4px;
}

.controls {
    align-items: center;
    display: flex;
    gap: 8px;
}

.days-select {
    width: 130px;
}

.state-alert {
    margin-bottom: 14px;
}

.chart {
    height: 360px;
    min-height: 300px;
}
</style>
