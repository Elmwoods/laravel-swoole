<template>
    <div class="alert-heatmap" v-loading="loading">
        <div class="toolbar">
            <span class="generated">最近更新：{{ data?.generated_at || '-' }}</span>
            <el-radio-group v-model="days" @change="load">
                <el-radio-button :value="7">7 天</el-radio-button>
                <el-radio-button :value="30">30 天</el-radio-button>
                <el-radio-button :value="90">90 天</el-radio-button>
            </el-radio-group>
            <el-button :loading="loading" @click="load">刷新</el-button>
        </div>

        <el-card shadow="never">
            <template #header>告警频率热力图（小时 × 星期）</template>
            <el-empty v-if="!hasData" description="暂无告警" />
            <div v-show="hasData" ref="heatRef" class="heat-chart"></div>
        </el-card>

        <div class="detail-grid">
            <el-card shadow="never">
                <template #header>最吵来源（近 {{ data?.window_days ?? days }} 天）</template>
                <el-table :data="data?.sources || []" border empty-text="暂无数据">
                    <el-table-column label="来源" prop="source" min-width="140" />
                    <el-table-column label="告警数" prop="total" width="120" />
                </el-table>
            </el-card>

            <el-card shadow="never">
                <template #header>每日趋势</template>
                <el-empty v-if="!data?.trend.length" description="暂无数据" :image-size="60" />
                <div v-show="data?.trend.length" ref="trendRef" class="trend-chart"></div>
            </el-card>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import * as echarts from 'echarts'
import { getAlertHeatmap, type AlertHeatmapResult } from '@/api/opsStage4'

const loading = ref(false)
const days = ref(30)
const data = ref<AlertHeatmapResult | null>(null)
const heatRef = ref<HTMLDivElement>()
const trendRef = ref<HTMLDivElement>()
let heatChart: echarts.ECharts | null = null
let trendChart: echarts.ECharts | null = null

const WEEKDAYS = ['周日', '周一', '周二', '周三', '周四', '周五', '周六']
const hasData = computed(() => (data.value?.buckets || []).some(b => b.count > 0))

const renderHeat = () => {
    if (!heatRef.value || !data.value) return
    if (!heatChart) heatChart = echarts.init(heatRef.value)
    const max = Math.max(1, ...data.value.buckets.map(b => b.count))
    heatChart.setOption({
        tooltip: {
            position: 'top',
            formatter: (p: any) => `${WEEKDAYS[p.value[1]]} ${p.value[0]}:00 — ${p.value[2]} 条`,
        },
        grid: { top: 16, left: 56, right: 24, bottom: 76, containLabel: true },
        xAxis: {
            type: 'category',
            data: Array.from({ length: 24 }, (_, h) => `${h}`),
            splitArea: { show: true },
            axisLabel: { interval: 0, fontSize: 11 },
        },
        yAxis: { type: 'category', data: WEEKDAYS, splitArea: { show: true } },
        visualMap: { min: 0, max, calculable: true, orient: 'horizontal', left: 'center', bottom: 4, itemWidth: 14, itemHeight: 90 },
        series: [{
            type: 'heatmap',
            data: data.value.buckets.map(b => [b.hour, b.dow, b.count]),
            label: { show: false },
        }],
    })
    heatChart.resize()
}

const renderTrend = () => {
    if (!trendRef.value || !data.value) return
    if (!trendChart) trendChart = echarts.init(trendRef.value)
    trendChart.setOption({
        tooltip: { trigger: 'axis' },
        grid: { top: 20, left: 8, right: 16, bottom: 8, containLabel: true },
        xAxis: { type: 'category', data: data.value.trend.map(t => t.date) },
        yAxis: { type: 'value' },
        series: [{ type: 'line', smooth: true, data: data.value.trend.map(t => t.count) }],
    })
    trendChart.resize()
}

const load = async () => {
    loading.value = true
    try {
        const res = await getAlertHeatmap(days.value)
        data.value = res.data.data
        await nextTick()
        renderHeat()
        renderTrend()
    } catch {
        ElMessage.error('热力图加载失败')
    } finally {
        loading.value = false
    }
}

const onResize = () => {
    heatChart?.resize()
    trendChart?.resize()
}

onMounted(() => {
    load()
    window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize)
    heatChart?.dispose()
    trendChart?.dispose()
    heatChart = null
    trendChart = null
})
</script>

<style scoped>
.alert-heatmap {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.toolbar {
    align-items: center;
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.generated {
    color: #94a3b8;
    font-size: 13px;
    margin-right: auto;
}

.heat-chart {
    height: 380px;
    width: 100%;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    gap: 16px;
}

.trend-chart {
    height: 240px;
    width: 100%;
}
</style>
