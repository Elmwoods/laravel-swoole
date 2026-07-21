<template>
    <div class="inspection-page">
        <section class="overview-band">
            <div>
                <div class="eyebrow">Automated Inspection</div>
                <h2>自动巡检</h2>
                <p>聚合发布自检、告警评估、日志错误扫描与队列健康的定时巡检，失败会自动升级为告警并推送通知。</p>
            </div>

            <el-button :loading="running" type="primary" :icon="Refresh" @click="runInspectionNow">
                运行巡检
            </el-button>
        </section>

        <section class="summary-grid">
            <div class="summary-tile">
                <span>最新状态</span>
                <strong>
                    <el-tag v-if="latest" :type="statusType(latest.status)" effect="dark">
                        {{ statusText(latest.status) }}
                    </el-tag>
                    <el-tag v-else type="info" effect="plain">未运行</el-tag>
                </strong>
            </div>
            <div class="summary-tile pass"><span>Pass</span><strong>{{ activeSummary.pass }}</strong></div>
            <div class="summary-tile warn"><span>Warn</span><strong>{{ activeSummary.warn }}</strong></div>
            <div class="summary-tile fail"><span>Fail</span><strong>{{ activeSummary.fail }}</strong></div>
        </section>

        <section class="info-band">
            <div>
                <span>调度</span>
                <p>每 15 分钟自动执行一次轻量巡检；手动运行为完整巡检（含发布自检）。</p>
            </div>
            <div>
                <span>CLI</span>
                <code>php artisan ops:inspections:run --type=full</code>
            </div>
        </section>

        <section class="checks-panel">
            <div class="panel-header">
                <div>
                    <h3>检查结果</h3>
                    <p>{{ activeRecord ? `记录 #${activeRecord.id}` : '运行巡检或在历史中打开详情后展示完整检查项。' }}</p>
                </div>

                <el-select v-model="groupFilter" clearable placeholder="全部分组" class="group-filter">
                    <el-option v-for="group in groups" :key="group" :label="group" :value="group" />
                </el-select>
            </div>

            <el-table v-loading="running" :data="filteredChecks" border empty-text="暂无检查结果">
                <el-table-column label="分组" prop="group" min-width="120" />
                <el-table-column label="检查项" prop="name" min-width="170" />
                <el-table-column label="状态" width="90">
                    <template #default="{ row }">
                        <el-tag :type="statusType(row.status)" effect="plain">{{ statusText(row.status) }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="信息" prop="message" min-width="240" />
                <el-table-column label="建议" prop="hint" min-width="260" />
            </el-table>
        </section>

        <section class="history-panel">
            <div class="panel-header">
                <div>
                    <h3>巡检历史</h3>
                    <p>历史按保留天数自动清理，单条记录只保存脱敏后的结构化结果。</p>
                </div>

                <el-button :loading="historyLoading" :icon="Refresh" @click="loadHistory">刷新</el-button>
            </div>

            <el-table v-loading="historyLoading" :data="history" border empty-text="暂无巡检历史">
                <el-table-column label="ID" prop="id" width="80" />
                <el-table-column label="状态" width="90">
                    <template #default="{ row }">
                        <el-tag :type="statusType(row.status)" effect="plain">{{ statusText(row.status) }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="类型" width="90">
                    <template #default="{ row }">{{ typeText(row.type) }}</template>
                </el-table-column>
                <el-table-column label="触发" width="90">
                    <template #default="{ row }">{{ triggerText(row.trigger) }}</template>
                </el-table-column>
                <el-table-column label="Pass/Warn/Fail" min-width="140">
                    <template #default="{ row }">
                        {{ row.summary.pass }} / {{ row.summary.warn }} / {{ row.summary.fail }}
                    </template>
                </el-table-column>
                <el-table-column label="执行人" prop="admin_email" min-width="170" />
                <el-table-column label="耗时" width="100">
                    <template #default="{ row }">{{ row.duration_ms }} ms</template>
                </el-table-column>
                <el-table-column label="完成时间" prop="finished_at" min-width="170" />
                <el-table-column label="操作" width="100" fixed="right">
                    <template #default="{ row }">
                        <el-button text type="primary" :icon="View" @click="openDetail(row.id)">详情</el-button>
                    </template>
                </el-table-column>
            </el-table>

            <div class="pagination">
                <el-pagination
                    v-model:current-page="pagination.current_page"
                    v-model:page-size="pagination.per_page"
                    :page-sizes="[10, 20, 50, 100]"
                    layout="total, sizes, prev, pager, next"
                    :total="pagination.total"
                    @current-change="loadHistory"
                    @size-change="changePageSize"
                />
            </div>
        </section>

        <el-drawer v-model="detailVisible" title="巡检详情" size="62%">
            <template v-if="detail">
                <div class="detail-summary">
                    <el-tag :type="statusType(detail.status)" effect="dark">{{ statusText(detail.status) }}</el-tag>
                    <span>#{{ detail.id }}</span>
                    <span>{{ typeText(detail.type) }} / {{ triggerText(detail.trigger) }}</span>
                    <span>{{ detail.finished_at || '-' }}</span>
                    <span>{{ detail.duration_ms }} ms</span>
                </div>

                <el-alert
                    v-if="detail.failure_message"
                    class="detail-failure"
                    type="error"
                    :closable="false"
                    show-icon
                    :title="detail.failure_message"
                />

                <el-table :data="detail.checks" border empty-text="暂无检查项">
                    <el-table-column label="分组" prop="group" min-width="120" />
                    <el-table-column label="检查项" prop="name" min-width="170" />
                    <el-table-column label="状态" width="90">
                        <template #default="{ row }">
                            <el-tag :type="statusType(row.status)" effect="plain">{{ statusText(row.status) }}</el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="信息" prop="message" min-width="240" />
                    <el-table-column label="建议" prop="hint" min-width="260" />
                </el-table>
            </template>
        </el-drawer>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh, View } from '@element-plus/icons-vue'
import {
    getInspectionDetail,
    getInspectionHistory,
    getInspectionSummary,
    runInspection,
    type InspectionCheckItem,
    type InspectionRecordDetail,
    type InspectionRecordSummary,
    type InspectionStatus,
    type InspectionSummaryCounts,
    type InspectionTrigger,
    type InspectionType,
} from '@/api/opsInspection'

const latestSummary = ref<InspectionRecordSummary | null>(null)
const summaryCounts = ref<InspectionSummaryCounts>({ pass: 0, warn: 0, fail: 0 })
const activeRecord = ref<InspectionRecordDetail | null>(null)
const history = ref<InspectionRecordSummary[]>([])
const detail = ref<InspectionRecordDetail | null>(null)
const running = ref(false)
const historyLoading = ref(false)
const detailVisible = ref(false)
const groupFilter = ref('')
const pagination = reactive({
    current_page: 1,
    per_page: 20,
    total: 0,
    last_page: 1,
})

const latest = computed(() => activeRecord.value || latestSummary.value || history.value[0] || null)
const activeSummary = computed(() => activeRecord.value?.summary || latestSummary.value?.summary || summaryCounts.value)
const activeChecks = computed<InspectionCheckItem[]>(() => activeRecord.value?.checks || [])
const groups = computed(() => Array.from(new Set(activeChecks.value.map(check => check.group))).filter(Boolean))
const filteredChecks = computed(() => {
    if (!groupFilter.value) return activeChecks.value

    return activeChecks.value.filter(check => check.group === groupFilter.value)
})

const statusType = (status: InspectionStatus) => {
    if (status === 'pass') return 'success'
    if (status === 'warn') return 'warning'

    return 'danger'
}

const statusText = (status: InspectionStatus) => {
    if (status === 'pass') return '通过'
    if (status === 'warn') return '警告'

    return '失败'
}

const typeText = (type: InspectionType) => (type === 'full' ? '完整' : '轻量')
const triggerText = (trigger: InspectionTrigger) => (trigger === 'manual' ? '手动' : '定时')

const loadSummary = async () => {
    const res = await getInspectionSummary()
    latestSummary.value = res.data.data.latest
    summaryCounts.value = res.data.data.summary
}

const loadHistory = async () => {
    historyLoading.value = true

    try {
        const res = await getInspectionHistory({
            page: pagination.current_page,
            per_page: pagination.per_page,
        })
        history.value = res.data.data.items
        Object.assign(pagination, res.data.data.pagination)
    } finally {
        historyLoading.value = false
    }
}

const runInspectionNow = async () => {
    running.value = true

    try {
        const res = await runInspection()
        activeRecord.value = res.data.data
        latestSummary.value = res.data.data
        summaryCounts.value = res.data.data.summary
        groupFilter.value = ''
        ElMessage.success('巡检已完成')
        await loadHistory()
    } finally {
        running.value = false
    }
}

const changePageSize = async (size: number) => {
    pagination.per_page = size
    pagination.current_page = 1
    await loadHistory()
}

const openDetail = async (id: number) => {
    const res = await getInspectionDetail(id)
    detail.value = res.data.data
    detailVisible.value = true
}

onMounted(async () => {
    await loadSummary()
    await loadHistory()
})
</script>

<style scoped>
.inspection-page {
    display: grid;
    gap: 16px;
}

.overview-band,
.checks-panel,
.history-panel,
.info-band {
    background: #fff;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    padding: 16px;
}

.overview-band {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.eyebrow {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0;
    text-transform: uppercase;
}

h2,
h3,
p {
    margin: 0;
}

h2 {
    color: #102033;
    font-size: 24px;
    margin-top: 4px;
}

h3 {
    color: #102033;
    font-size: 16px;
}

p {
    color: #64748b;
    margin-top: 6px;
}

.summary-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-tile {
    background: #fff;
    border: 1px solid #dbe3ef;
    border-left: 4px solid #7c8da5;
    border-radius: 8px;
    display: grid;
    gap: 8px;
    min-height: 78px;
    padding: 14px;
}

.summary-tile span {
    color: #64748b;
    font-size: 13px;
}

.summary-tile strong {
    color: #102033;
    font-size: 26px;
    line-height: 1;
}

.summary-tile.pass {
    border-left-color: #16a34a;
}

.summary-tile.warn {
    border-left-color: #d97706;
}

.summary-tile.fail {
    border-left-color: #dc2626;
}

.info-band {
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.info-band div {
    display: grid;
    gap: 4px;
}

.info-band span {
    color: #64748b;
    font-size: 12px;
}

code {
    color: #334155;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    white-space: normal;
    word-break: break-word;
}

.panel-header {
    align-items: center;
    display: flex;
    gap: 12px;
    justify-content: space-between;
    margin-bottom: 14px;
}

.group-filter {
    width: 180px;
}

.pagination {
    display: flex;
    justify-content: flex-end;
    margin-top: 12px;
}

.detail-summary {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 14px;
}

.detail-failure {
    margin-bottom: 14px;
}

@media (max-width: 900px) {
    .overview-band,
    .panel-header {
        align-items: stretch;
        flex-direction: column;
    }

    .summary-grid,
    .info-band {
        grid-template-columns: 1fr;
    }

    .group-filter {
        width: 100%;
    }
}
</style>
