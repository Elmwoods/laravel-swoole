<template>
    <div class="alert-topology" v-loading="loading">
        <div class="toolbar">
            <span class="generated">来源依赖关联（{{ data?.enabled ? '已启用' : '未启用' }}）</span>
            <el-button :loading="loading" @click="load">刷新</el-button>
        </div>

        <el-card shadow="never">
            <template #header>
                服务依赖拓扑
                <span class="legend"><i class="dot green"></i>正常 <i class="dot red"></i>firing <i class="dot amber"></i>被抑制</span>
            </template>
            <el-empty v-if="!data || !data.nodes.length" description="暂无依赖配置（config alerts.correlation.dependencies）" />
            <div v-show="data && data.nodes.length" ref="graphRef" class="graph-chart"></div>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, nextTick, ref } from 'vue'
import { ElMessage } from 'element-plus'
import * as echarts from 'echarts'
import { getAlertTopology, type AlertTopologyResult } from '@/api/opsStage4'

const loading = ref(false)
const data = ref<AlertTopologyResult | null>(null)
const graphRef = ref<HTMLDivElement>()
let chart: echarts.ECharts | null = null

const colorOf = (n: { firing: boolean; suppressed: boolean }) =>
    n.suppressed ? '#f59e0b' : n.firing ? '#ef4444' : '#22c55e'

const render = () => {
    if (!graphRef.value || !data.value) return
    if (!chart) chart = echarts.init(graphRef.value)
    chart.setOption({
        tooltip: {
            formatter: (p: any) => (p.dataType === 'node'
                ? `${p.name}<br/>open: ${p.value ?? 0}`
                : `${p.data.source} → ${p.data.target}`),
        },
        series: [{
            type: 'graph',
            layout: 'force',
            roam: true,
            force: { repulsion: 260, edgeLength: 130 },
            edgeSymbol: ['none', 'arrow'],
            edgeSymbolSize: 8,
            label: { show: true, position: 'right' },
            symbolSize: 44,
            data: data.value.nodes.map(n => ({
                name: n.source,
                value: n.open,
                itemStyle: { color: colorOf(n) },
            })),
            links: data.value.edges.map(e => ({ source: e.from, target: e.to })),
        }],
    })
    chart.resize()
}

const load = async () => {
    loading.value = true
    try {
        const res = await getAlertTopology()
        data.value = res.data.data
        await nextTick()
        render()
    } catch {
        ElMessage.error('拓扑加载失败')
    } finally {
        loading.value = false
    }
}

const onResize = () => chart?.resize()

onMounted(() => {
    load()
    window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize)
    chart?.dispose()
    chart = null
})
</script>

<style scoped>
.alert-topology {
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

.graph-chart {
    height: 460px;
    width: 100%;
}

.legend {
    color: #94a3b8;
    font-size: 12px;
    margin-left: 12px;
}

.dot {
    border-radius: 50%;
    display: inline-block;
    height: 9px;
    margin: 0 3px 0 8px;
    width: 9px;
}

.dot.green { background: #22c55e; }
.dot.red { background: #ef4444; }
.dot.amber { background: #f59e0b; }
</style>
