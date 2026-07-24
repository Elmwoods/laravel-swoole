<template>
    <div class="security-overview" v-loading="loading">
        <div class="toolbar">
            <span class="generated">最近更新：{{ data?.generated_at || '-' }}</span>
            <el-button :loading="loading" @click="load">刷新</el-button>
        </div>

        <div class="summary-grid">
            <el-card v-for="card in summaryCards" :key="card.title" shadow="never" class="summary-card">
                <div class="summary-head">
                    <span class="summary-title">{{ card.title }}</span>
                    <el-tag :type="card.tagType" effect="plain">{{ card.status }}</el-tag>
                </div>
                <div class="summary-value">{{ card.value }}</div>
                <div class="summary-desc">{{ card.desc }}</div>
            </el-card>
        </div>

        <div class="detail-grid">
            <el-card shadow="never">
                <template #header>失败登录 Top IP（近 24h）</template>
                <el-table :data="data?.failed_logins.top_ips || []" border empty-text="暂无失败登录">
                    <el-table-column label="来源 IP" prop="ip" />
                    <el-table-column label="次数" prop="total" width="100" />
                </el-table>
            </el-card>

            <el-card shadow="never">
                <template #header>开放安全告警（按来源）</template>
                <el-table :data="data?.security_alerts.by_source || []" border empty-text="暂无安全告警">
                    <el-table-column label="来源" width="160">
                        <template #default="{ row }">{{ sourceLabel(row.source) }}</template>
                    </el-table-column>
                    <el-table-column label="数量" prop="total" width="100" />
                </el-table>
            </el-card>

            <el-card shadow="never">
                <template #header>通知通道健康</template>
                <div class="channel-health">
                    <el-tag type="success" effect="plain">正常 {{ data?.channel_health.healthy ?? 0 }}</el-tag>
                    <el-tag type="danger" effect="plain">异常 {{ data?.channel_health.failing ?? 0 }}</el-tag>
                    <el-tag type="info" effect="plain">未检测 {{ data?.channel_health.unknown ?? 0 }}</el-tag>
                </div>
                <div class="channel-note">共 {{ data?.channel_health.total ?? 0 }} 个已配置通道</div>
            </el-card>
        </div>

        <el-card shadow="never" class="trend-card">
            <template #header>失败登录趋势（近 7 天）</template>
            <el-empty v-if="!trendHasData" description="暂无失败登录" />
            <div v-show="trendHasData" ref="trendRef" class="trend-chart"></div>
        </el-card>

        <el-card shadow="never">
            <template #header>近期安全事件</template>
            <el-empty v-if="!data?.recent_events.length" description="暂无安全事件" />
            <el-timeline v-else>
                <el-timeline-item
                    v-for="(event, index) in data.recent_events"
                    :key="index"
                    :timestamp="event.at || ''"
                    :type="severityType(event.severity)"
                >
                    <span class="event-source">{{ sourceLabel(event.source) }}</span>
                    <span>{{ event.title }}</span>
                    <el-tag size="small" :type="event.status === 'open' ? 'warning' : 'info'" effect="plain">
                        {{ statusLabel(event.status) }}
                    </el-tag>
                </el-timeline-item>
            </el-timeline>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import * as echarts from 'echarts'
import { getSecurityOverview, type SecurityOverview } from '@/api/opsSecurity'

const loading = ref(false)
const data = ref<SecurityOverview | null>(null)
const trendRef = ref<HTMLDivElement>()
let trendChart: echarts.ECharts | null = null

const SOURCE_LABELS: Record<string, string> = {
    security_login: '异常登录',
    security_audit: '审计异常',
    security_access: '来源封禁',
    channel_health: '通道健康',
    disk: '磁盘',
}
const sourceLabel = (s: string) => SOURCE_LABELS[s] ?? s
const statusLabel = (s: string) => (s === 'open' ? '待处理' : s === 'acknowledged' ? '已确认' : '已恢复')
const severityType = (s: string) => (s === 'critical' ? 'danger' : s === 'warning' ? 'warning' : 'info')

const trendHasData = computed(() => (data.value?.failed_login_trend.length ?? 0) > 0)

const summaryCards = computed(() => {
    const d = data.value
    const failing = d?.channel_health.failing ?? 0
    const failed = d?.failed_logins.total ?? 0
    const openAlerts = d?.security_alerts.open_total ?? 0
    const coverage = d?.two_factor.coverage_percent ?? 0
    return [
        { title: '新 IP 登录（7d）', value: d?.login_risk.new_ip_logins ?? 0, desc: `新设备 ${d?.login_risk.new_device_logins ?? 0} · 总登录 ${d?.login_risk.total_logins ?? 0}`, status: (d?.login_risk.new_ip_logins ?? 0) > 0 ? '关注' : '正常', tagType: (d?.login_risk.new_ip_logins ?? 0) > 0 ? 'warning' : 'success' },
        { title: '失败登录（24h）', value: failed, desc: `Top IP ${d?.failed_logins.top_ips[0]?.ip ?? '—'}`, status: failed > 0 ? '关注' : '正常', tagType: failed > 0 ? 'warning' : 'success' },
        { title: '活跃会话', value: d?.sessions.active ?? 0, desc: `${d?.sessions.distinct_admins ?? 0} 名管理员`, status: '实时', tagType: 'info' },
        { title: '2FA 覆盖率', value: `${coverage}%`, desc: `${d?.two_factor.enabled ?? 0}/${d?.two_factor.active_admins ?? 0} 已启用`, status: coverage >= 100 ? '达标' : '待提升', tagType: coverage >= 100 ? 'success' : 'warning' },
        { title: '受信任设备', value: d?.trusted_devices.active ?? 0, desc: '有效期内', status: '实时', tagType: 'info' },
        { title: '开放安全告警', value: openAlerts, desc: `严重 ${d?.security_alerts.by_severity.critical ?? 0} · 警告 ${d?.security_alerts.by_severity.warning ?? 0}`, status: (d?.security_alerts.by_severity.critical ?? 0) > 0 ? '严重' : openAlerts > 0 ? '关注' : '正常', tagType: (d?.security_alerts.by_severity.critical ?? 0) > 0 ? 'danger' : openAlerts > 0 ? 'warning' : 'success' },
        { title: 'IP 封禁', value: (d?.ip_rules.deny_active ?? 0), desc: `其中自动 ${d?.ip_rules.auto_ban_active ?? 0} · 白名单 ${d?.ip_rules.allow_active ?? 0}`, status: '生效中', tagType: 'info' },
        { title: '通道异常', value: failing, desc: `共 ${d?.channel_health.total ?? 0} 个通道`, status: failing > 0 ? '异常' : '正常', tagType: failing > 0 ? 'danger' : 'success' },
    ]
})

const renderTrend = () => {
    if (!trendHasData.value || !trendRef.value) return
    if (!trendChart) trendChart = echarts.init(trendRef.value)
    const trend = data.value!.failed_login_trend
    trendChart.setOption({
        tooltip: { trigger: 'axis' },
        grid: { left: 10, right: 16, bottom: 10, top: 30, containLabel: true },
        xAxis: { type: 'category', data: trend.map(t => t.date) },
        yAxis: { type: 'value', minInterval: 1 },
        series: [{ name: '失败登录', type: 'line', smooth: true, areaStyle: {}, data: trend.map(t => t.total) }],
    })
    trendChart.resize()
}

const load = async () => {
    loading.value = true
    try {
        const res = await getSecurityOverview()
        data.value = res.data.data
        await nextTick()
        renderTrend()
    } catch {
        ElMessage.error('安全总览加载失败')
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
.security-overview {
    display: grid;
    gap: 16px;
}

.toolbar {
    align-items: center;
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.generated {
    color: #64748b;
    font-size: 13px;
}

.summary-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-card {
    min-height: 108px;
}

.summary-head {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.summary-title {
    color: #475569;
    font-size: 13px;
}

.summary-value {
    font-size: 26px;
    font-weight: 600;
    margin-top: 6px;
}

.summary-desc {
    color: #94a3b8;
    font-size: 12px;
    margin-top: 4px;
}

.detail-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.channel-health {
    display: flex;
    gap: 8px;
}

.channel-note {
    color: #94a3b8;
    font-size: 12px;
    margin-top: 10px;
}

.trend-chart {
    height: 260px;
    width: 100%;
}

.event-source {
    color: #64748b;
    margin-right: 8px;
}

@media (max-width: 1200px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .detail-grid {
        grid-template-columns: 1fr;
    }
}
</style>
