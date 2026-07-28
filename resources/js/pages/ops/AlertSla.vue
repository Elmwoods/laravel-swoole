<template>
    <div class="alert-sla" v-loading="loading">
        <el-alert
            v-if="(data?.open_breaches ?? 0) > 0"
            type="error"
            show-icon
            :closable="false"
            :title="`当前 ${data?.open_breaches} 条 SLA 违约告警未关闭（告警中心 source=sla_breach）。`"
        />

        <div class="toolbar">
            <el-radio-group v-model="days" @change="load">
                <el-radio-button :value="7">近 7 天</el-radio-button>
                <el-radio-button :value="30">近 30 天</el-radio-button>
                <el-radio-button :value="90">近 90 天</el-radio-button>
            </el-radio-group>
            <span class="generated">最近更新：{{ data?.generated_at || '-' }}</span>
            <el-button :loading="loading" @click="load">刷新</el-button>
        </div>

        <div class="summary-grid">
            <el-card v-for="card in summaryCards" :key="card.title" shadow="never" class="summary-card">
                <div class="summary-title">{{ card.title }}</div>
                <div class="summary-value" :class="{ danger: card.danger }">{{ card.value }}</div>
                <div class="summary-desc">{{ card.desc }}</div>
            </el-card>
        </div>

        <div class="detail-grid">
            <el-card shadow="never">
                <template #header>按来源</template>
                <el-table :data="data?.by_source || []" border empty-text="暂无已处理告警">
                    <el-table-column label="来源" prop="source" />
                    <el-table-column label="MTTR（平均恢复）" width="160">
                        <template #default="{ row }">{{ fmt(row.mttr_avg_seconds) }}（{{ row.mttr_count }}）</template>
                    </el-table-column>
                    <el-table-column label="MTTA（平均确认）" width="160">
                        <template #default="{ row }">{{ row.mtta_count ? fmt(row.mtta_avg_seconds) : '—' }}</template>
                    </el-table-column>
                </el-table>
            </el-card>

            <el-card shadow="never">
                <template #header>按严重级（MTTR）</template>
                <div class="severity-row">
                    <div v-for="s in severityRows" :key="s.key" class="severity-item">
                        <el-tag :type="s.tag" effect="plain">{{ s.label }}</el-tag>
                        <span class="severity-val">{{ s.count ? fmt(s.avg) : '—' }}</span>
                        <span class="severity-count">{{ s.count }} 条</span>
                    </div>
                </div>
                <el-divider content-position="left">当前积压（open）按拖延时长</el-divider>
                <div class="aging-row">
                    <el-tag type="success" effect="plain">&lt;1h {{ data?.open_aging.under_1h ?? 0 }}</el-tag>
                    <el-tag type="warning" effect="plain">1–24h {{ data?.open_aging.one_to_24h ?? 0 }}</el-tag>
                    <el-tag type="danger" effect="plain">&gt;24h {{ data?.open_aging.over_24h ?? 0 }}</el-tag>
                </div>
            </el-card>
        </div>

        <el-card shadow="never" class="trend-card">
            <template #header>MTTR 趋势（按恢复日期，分钟）</template>
            <el-empty v-if="!trendHasData" description="暂无恢复数据" />
            <div v-show="trendHasData" ref="trendRef" class="trend-chart"></div>
        </el-card>

        <el-card shadow="never">
            <template #header>
                SLA 目标（{{ data?.targets.enabled ? '已启用' : '未启用（OPS_ALERT_SLA_ENABLED）' }}）
            </template>
            <el-table :data="targetRows" border>
                <el-table-column label="严重级" prop="label" width="120" />
                <el-table-column label="确认时限（MTTA 目标）">
                    <template #default="{ row }">{{ row.ack }} 分钟</template>
                </el-table-column>
                <el-table-column label="恢复时限（MTTR 目标）">
                    <template #default="{ row }">{{ row.resolve }} 分钟</template>
                </el-table-column>
            </el-table>
        </el-card>

        <el-card shadow="never">
            <template #header>
                SLA 达标率
                <span class="muted">（窗口内在目标时限内完成的比例）</span>
            </template>
            <div class="compliance-grid">
                <div class="compliance-col">
                    <div class="compliance-title">
                        确认达标率（ack）
                        <el-tag v-if="ackOverall" size="small" :type="rateTag(ackOverall.rate)" effect="plain">
                            总体 {{ ratePct(ackOverall.rate) }}（{{ ackOverall.within }}/{{ ackOverall.total }}）
                        </el-tag>
                    </div>
                    <div v-for="row in ackComplianceRows" :key="row.key" class="compliance-row">
                        <el-tag :type="row.tag" effect="plain" class="compliance-sev">{{ row.label }}</el-tag>
                        <el-progress
                            :percentage="row.rate ?? 0"
                            :status="row.rate === null ? undefined : (row.rate >= 90 ? 'success' : row.rate >= 60 ? 'warning' : 'exception')"
                            class="compliance-bar"
                        />
                        <span class="compliance-count">{{ row.rate === null ? '无数据' : `${row.within}/${row.total}` }}</span>
                    </div>
                </div>
                <div class="compliance-col">
                    <div class="compliance-title">
                        恢复达标率（resolve）
                        <el-tag v-if="resolveOverall" size="small" :type="rateTag(resolveOverall.rate)" effect="plain">
                            总体 {{ ratePct(resolveOverall.rate) }}（{{ resolveOverall.within }}/{{ resolveOverall.total }}）
                        </el-tag>
                    </div>
                    <div v-for="row in resolveComplianceRows" :key="row.key" class="compliance-row">
                        <el-tag :type="row.tag" effect="plain" class="compliance-sev">{{ row.label }}</el-tag>
                        <el-progress
                            :percentage="row.rate ?? 0"
                            :status="row.rate === null ? undefined : (row.rate >= 90 ? 'success' : row.rate >= 60 ? 'warning' : 'exception')"
                            class="compliance-bar"
                        />
                        <span class="compliance-count">{{ row.rate === null ? '无数据' : `${row.within}/${row.total}` }}</span>
                    </div>
                </div>
            </div>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import * as echarts from 'echarts'
import { getAlertSla, type AlertSlaResult } from '@/api/opsStage4'

const loading = ref(false)
const days = ref(30)
const data = ref<AlertSlaResult | null>(null)
const trendRef = ref<HTMLDivElement>()
let trendChart: echarts.ECharts | null = null

const fmt = (seconds: number): string => {
    if (!seconds || seconds < 0) return '0秒'
    if (seconds < 60) return `${seconds}秒`
    if (seconds < 3600) {
        const m = Math.floor(seconds / 60)
        const s = seconds % 60
        return s ? `${m}分${s}秒` : `${m}分`
    }
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    return m ? `${h}小时${m}分` : `${h}小时`
}

const trendHasData = computed(() => (data.value?.trend.length ?? 0) > 0)

const targetRows = computed(() => {
    const t = data.value?.targets
    return [
        { label: '严重', ack: t?.ack_minutes.critical ?? '—', resolve: t?.resolve_minutes.critical ?? '—' },
        { label: '警告', ack: t?.ack_minutes.warning ?? '—', resolve: t?.resolve_minutes.warning ?? '—' },
        { label: '提示', ack: t?.ack_minutes.info ?? '—', resolve: t?.resolve_minutes.info ?? '—' },
    ]
})

const summaryCards = computed(() => {
    const d = data.value
    const aging = d?.open_aging
    const backlog = (aging?.under_1h ?? 0) + (aging?.one_to_24h ?? 0) + (aging?.over_24h ?? 0)
    return [
        { title: 'MTTA（平均确认时长）', value: d?.mtta.count ? fmt(d.mtta.avg_seconds) : '—', desc: `${d?.mtta.count ?? 0} 条已确认`, danger: false },
        { title: 'MTTR（平均恢复时长）', value: d?.mttr.count ? fmt(d.mttr.avg_seconds) : '—', desc: `最长 ${d?.mttr.count ? fmt(d.mttr.max_seconds) : '—'}`, danger: false },
        { title: '已恢复', value: d?.mttr.count ?? 0, desc: `窗口内恢复告警数`, danger: false },
        { title: '当前积压', value: backlog, desc: `其中 >24h ${aging?.over_24h ?? 0} 条`, danger: (aging?.over_24h ?? 0) > 0 },
    ]
})

const severityRows = computed(() => {
    const bs = data.value?.by_severity
    return [
        { key: 'critical', label: '严重', tag: 'danger' as const, avg: bs?.critical.mttr_avg_seconds ?? 0, count: bs?.critical.mttr_count ?? 0 },
        { key: 'warning', label: '警告', tag: 'warning' as const, avg: bs?.warning.mttr_avg_seconds ?? 0, count: bs?.warning.mttr_count ?? 0 },
        { key: 'info', label: '提示', tag: 'info' as const, avg: bs?.info.mttr_avg_seconds ?? 0, count: bs?.info.mttr_count ?? 0 },
    ]
})

const SEV_META = [
    { key: 'critical', label: '严重', tag: 'danger' as const },
    { key: 'warning', label: '警告', tag: 'warning' as const },
    { key: 'info', label: '提示', tag: 'info' as const },
]

const complianceRows = (kind: 'ack' | 'resolve') =>
    SEV_META.map(m => {
        const cell = data.value?.compliance?.[kind]?.[m.key as 'critical' | 'warning' | 'info']
        return { ...m, within: cell?.within ?? 0, total: cell?.total ?? 0, rate: cell?.rate ?? null }
    })

const ackComplianceRows = computed(() => complianceRows('ack'))
const resolveComplianceRows = computed(() => complianceRows('resolve'))
const ackOverall = computed(() => data.value?.compliance?.ack?.overall ?? null)
const resolveOverall = computed(() => data.value?.compliance?.resolve?.overall ?? null)

const ratePct = (rate: number | null): string => (rate === null ? '无数据' : `${rate}%`)
const rateTag = (rate: number | null): 'success' | 'warning' | 'danger' | 'info' => {
    if (rate === null) return 'info'
    return rate >= 90 ? 'success' : rate >= 60 ? 'warning' : 'danger'
}

const renderTrend = () => {
    if (!trendHasData.value || !trendRef.value) return
    if (!trendChart) trendChart = echarts.init(trendRef.value)
    const trend = data.value!.trend
    trendChart.setOption({
        tooltip: { trigger: 'axis' },
        grid: { left: 10, right: 16, bottom: 10, top: 30, containLabel: true },
        xAxis: { type: 'category', data: trend.map(t => t.date) },
        yAxis: { type: 'value', name: '分钟', minInterval: 1 },
        series: [{ name: 'MTTR', type: 'line', smooth: true, areaStyle: {}, data: trend.map(t => Math.round(t.mttr_avg_seconds / 60)) }],
    })
    trendChart.resize()
}

const load = async () => {
    loading.value = true
    try {
        const res = await getAlertSla(days.value)
        data.value = res.data.data
        await nextTick()
        renderTrend()
    } catch {
        ElMessage.error('告警 SLA 加载失败')
    } finally {
        loading.value = false
    }
}

const onResize = () => trendChart?.resize()

onMounted(() => {
    load()
    window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize)
    trendChart?.dispose()
    trendChart = null
})
</script>

<style scoped>
.alert-sla {
    display: grid;
    gap: 16px;
}

.toolbar {
    align-items: center;
    display: flex;
    gap: 12px;
}

.generated {
    color: #64748b;
    font-size: 13px;
    margin-left: auto;
}

.summary-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-title {
    color: #475569;
    font-size: 13px;
}

.summary-value {
    font-size: 24px;
    font-weight: 600;
    margin-top: 6px;
}

.summary-value.danger {
    color: #f56c6c;
}

.summary-desc {
    color: #94a3b8;
    font-size: 12px;
    margin-top: 4px;
}

.detail-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.severity-row {
    display: grid;
    gap: 10px;
}

.compliance-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
}

.compliance-col {
    display: grid;
    gap: 10px;
}

.compliance-title {
    align-items: center;
    display: flex;
    font-weight: 600;
    gap: 8px;
}

.compliance-row {
    align-items: center;
    display: grid;
    grid-template-columns: 56px 1fr auto;
    gap: 10px;
}

.compliance-sev {
    justify-self: start;
}

.compliance-bar {
    min-width: 0;
}

.compliance-count {
    color: #94a3b8;
    font-size: 12px;
    white-space: nowrap;
}

.severity-item {
    align-items: center;
    display: flex;
    gap: 10px;
}

.severity-val {
    font-weight: 600;
}

.severity-count {
    color: #94a3b8;
    font-size: 12px;
}

.aging-row {
    display: flex;
    gap: 8px;
}

.trend-chart {
    height: 260px;
    width: 100%;
}

@media (max-width: 1100px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .detail-grid {
        grid-template-columns: 1fr;
    }
}
</style>
