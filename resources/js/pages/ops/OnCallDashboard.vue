<template>
    <div class="oncall-dashboard" v-loading="loading">
        <div class="toolbar">
            <span class="generated">最近更新：{{ data?.generated_at || '-' }}</span>
            <el-radio-group v-model="days" @change="load">
                <el-radio-button :value="7">7 天</el-radio-button>
                <el-radio-button :value="30">30 天</el-radio-button>
                <el-radio-button :value="90">90 天</el-radio-button>
            </el-radio-group>
            <el-button :loading="loading" @click="load">刷新</el-button>
        </div>

        <div class="summary-grid">
            <el-card v-for="card in summaryCards" :key="card.title" shadow="never" class="summary-card">
                <div class="summary-head">
                    <span class="summary-title">{{ card.title }}</span>
                    <el-tag v-if="card.tag" :type="card.tagType" effect="plain">{{ card.tag }}</el-tag>
                </div>
                <div class="summary-value" :class="{ danger: card.danger }">{{ card.value }}</div>
                <div class="summary-desc">{{ card.desc }}</div>
            </el-card>
        </div>

        <div class="detail-grid">
            <el-card shadow="never">
                <template #header>未来班次</template>
                <el-table :data="data?.upcoming_shifts || []" border empty-text="暂无未来班次">
                    <el-table-column label="值班人" prop="assignee" min-width="120" />
                    <el-table-column label="备注">
                        <template #default="{ row }">{{ row.label || '—' }}</template>
                    </el-table-column>
                    <el-table-column label="重复" width="90">
                        <template #default="{ row }">{{ recurrenceLabel(row.recurrence) }}</template>
                    </el-table-column>
                    <el-table-column label="开始时间" prop="starts_at" width="180" />
                </el-table>
            </el-card>

            <el-card shadow="never">
                <template #header>我的待处理（当前值班人）</template>
                <div class="severity-row">
                    <div class="severity-item">
                        <el-tag type="danger" effect="plain">严重</el-tag>
                        <span class="severity-val">{{ data?.my_open_alerts.critical ?? 0 }}</span>
                    </div>
                    <div class="severity-item">
                        <el-tag type="warning" effect="plain">警告</el-tag>
                        <span class="severity-val">{{ data?.my_open_alerts.warning ?? 0 }}</span>
                    </div>
                    <div class="severity-item">
                        <el-tag type="info" effect="plain">提示</el-tag>
                        <span class="severity-val">{{ data?.my_open_alerts.info ?? 0 }}</span>
                    </div>
                </div>
                <el-divider content-position="left">SLA 快照（近 {{ data?.sla.window_days ?? days }} 天）</el-divider>
                <div class="aging-row">
                    <el-tag type="success" effect="plain">&lt;1h {{ data?.sla.open_aging.under_1h ?? 0 }}</el-tag>
                    <el-tag type="warning" effect="plain">1–24h {{ data?.sla.open_aging.one_to_24h ?? 0 }}</el-tag>
                    <el-tag type="danger" effect="plain">&gt;24h {{ data?.sla.open_aging.over_24h ?? 0 }}</el-tag>
                </div>
                <div class="rate-row">
                    <span>确认达标率：<strong>{{ ratePct(data?.sla.ack_rate) }}</strong></span>
                    <span>恢复达标率：<strong>{{ ratePct(data?.sla.resolve_rate) }}</strong></span>
                </div>
            </el-card>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getOnCallDashboard, type OnCallDashboard } from '@/api/opsStage4'

const loading = ref(false)
const days = ref(7)
const data = ref<OnCallDashboard | null>(null)

const recurrenceLabel = (r: string) => (r === 'daily' ? '每天' : r === 'weekly' ? '每周' : '一次性')
const ratePct = (rate: number | null | undefined) => (rate === null || rate === undefined ? '无数据' : `${rate}%`)

const summaryCards = computed(() => {
    const d = data.value
    const breaches = d?.sla.open_breaches ?? 0
    return [
        { title: '当前值班人', value: d?.current_on_call || '无', desc: '开启自动指派后新告警归属', tag: d?.current_on_call ? '在岗' : '空缺', tagType: d?.current_on_call ? 'success' : 'info', danger: false },
        { title: '我的待处理', value: d?.my_open_alerts.total ?? 0, desc: `严重 ${d?.my_open_alerts.critical ?? 0}`, tag: '', tagType: 'info', danger: (d?.my_open_alerts.critical ?? 0) > 0 },
        { title: '未指派 open', value: d?.unassigned_open ?? 0, desc: '待认领告警', tag: '', tagType: 'info', danger: (d?.unassigned_open ?? 0) > 0 },
        { title: 'SLA 违约', value: breaches, desc: '当前 open 违约告警', tag: '', tagType: 'info', danger: breaches > 0 },
    ]
})

const load = async () => {
    loading.value = true
    try {
        const res = await getOnCallDashboard(days.value)
        data.value = res.data.data
    } catch {
        ElMessage.error('值班仪表盘加载失败')
    } finally {
        loading.value = false
    }
}

onMounted(load)
</script>

<style scoped>
.oncall-dashboard {
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

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}

.summary-head {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.summary-title {
    color: #64748b;
    font-size: 13px;
}

.summary-value {
    font-size: 26px;
    font-weight: 700;
    margin-top: 8px;
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
    grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    gap: 16px;
}

.severity-row {
    display: flex;
    gap: 24px;
}

.severity-item {
    align-items: center;
    display: flex;
    gap: 8px;
}

.severity-val {
    font-size: 20px;
    font-weight: 600;
}

.aging-row {
    display: flex;
    gap: 10px;
}

.rate-row {
    color: #475569;
    display: flex;
    gap: 24px;
    margin-top: 12px;
}
</style>
