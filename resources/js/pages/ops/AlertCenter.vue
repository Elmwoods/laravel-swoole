<template>
    <section class="alerts-page">
        <el-alert
            v-if="activeSilenceCount > 0"
            type="warning"
            show-icon
            :closable="false"
            class="silence-banner"
            :title="`${activeSilenceCount} 条告警静默生效中：命中的告警暂不外发到通道（仍会入库并在此列出）。`"
        />

        <div class="summary-grid">
            <el-card v-for="item in summaryCards" :key="item.label" shadow="never" class="summary-card">
                <div class="summary-label">{{ item.label }}</div>
                <div class="summary-value" :class="item.className">{{ item.value }}</div>
                <div class="summary-desc">{{ item.description }}</div>
            </el-card>
        </div>

        <el-card v-loading="trendLoading" shadow="never" class="trend-card">
            <div class="notification-header">
                <div>
                    <div class="panel-title">告警趋势</div>
                    <div class="panel-subtitle">近 {{ trendDays }} 天每日命中告警与自动恢复数量</div>
                </div>

                <el-select v-model="trendDays" size="small" class="trend-days" @change="loadTrend">
                    <el-option :value="7" label="近 7 天" />
                    <el-option :value="14" label="近 14 天" />
                    <el-option :value="30" label="近 30 天" />
                </el-select>
            </div>

            <el-empty v-if="!trendLoading && trendEmpty" description="暂无告警趋势数据" />
            <div v-show="!trendEmpty" ref="trendRef" class="trend-chart"></div>
        </el-card>

        <el-card shadow="never" class="notification-card">
            <div class="notification-header">
                <div>
                    <div class="panel-title">通知通道</div>
                    <div class="panel-subtitle">Telegram 与邮件告警配置状态</div>
                </div>

                <div class="notification-actions">
                    <el-button text :loading="runningHealthCheck" @click="handleHealthCheck">
                        立即自检
                    </el-button>
                    <el-button text :loading="notificationLoading" @click="loadNotificationStatus">
                        刷新状态
                    </el-button>
                </div>
            </div>

            <div class="notification-grid">
                <div
                    v-for="channel in notificationChannels"
                    :key="channel.name"
                    class="notification-item"
                >
                    <div class="channel-title">
                        <span>{{ channel.label }}</span>
                        <el-tag :type="channel.configured ? 'success' : 'warning'" effect="plain">
                            {{ channel.configured ? '可用' : '未就绪' }}
                        </el-tag>
                        <el-tooltip
                            v-if="channel.health && channel.health !== 'unknown'"
                            :content="channel.last_error || '连通正常'"
                            :disabled="channel.health === 'healthy'"
                            placement="top"
                        >
                            <el-tag :type="channel.health === 'healthy' ? 'success' : 'danger'">
                                {{ channel.health === 'healthy' ? '连通正常' : '连通异常' }}
                            </el-tag>
                        </el-tooltip>
                    </div>
                    <div class="channel-desc">
                        {{ channel.enabled ? '已启用' : '未启用' }}
                        <template v-if="channel.missing.length">
                            · 缺少 {{ channel.missing.join(', ') }}
                        </template>
                        <template v-if="channel.last_checked_at">
                            · 最近自检 {{ channel.last_checked_at }}
                        </template>
                    </div>
                </div>
            </div>

            <div class="settings-panel">
                <div class="panel-subtitle">通知策略</div>
                <el-form v-if="settingsDraft" class="settings-form" label-width="120px">
                    <el-form-item label="重复通知">
                        <el-input-number
                            v-model="settingsDraft.notification_repeat_minutes"
                            :min="0"
                            :max="1440"
                            controls-position="right"
                            :disabled="settingsSaving"
                        />
                        <span class="muted inline-help">分钟</span>
                    </el-form-item>
                    <el-form-item label="自动恢复">
                        <el-switch v-model="settingsDraft.auto_resolve_enabled" :disabled="settingsSaving" />
                        <el-input-number
                            v-model="settingsDraft.auto_resolve_grace_minutes"
                            :min="1"
                            :max="1440"
                            controls-position="right"
                            :disabled="settingsSaving"
                        />
                        <span class="muted inline-help">分钟宽限</span>
                    </el-form-item>
                    <el-form-item label="升级重推">
                        <el-switch v-model="settingsDraft.escalation_enabled" :disabled="settingsSaving" />
                        <el-input-number
                            v-model="settingsDraft.escalation_after_minutes"
                            :min="1"
                            :max="1440"
                            controls-position="right"
                            :disabled="settingsSaving"
                        />
                        <span class="muted inline-help">分钟未确认则升级重推</span>
                    </el-form-item>
                    <el-form-item label="通道策略">
                        <div class="severity-grid">
                            <div v-for="level in severityLevels" :key="level" class="severity-row">
                                <span class="severity-label">{{ level }}</span>
                                <el-checkbox
                                    v-for="ch in channelKeys"
                                    :key="ch"
                                    v-model="settingsDraft.severity_channels[level][ch]"
                                    :disabled="settingsSaving"
                                >
                                    {{ channelLabel(ch) }}
                                </el-checkbox>
                            </div>
                        </div>
                    </el-form-item>
                    <el-form-item label="总开关">
                        <el-checkbox
                            v-for="ch in channelKeys"
                            :key="ch"
                            v-model="settingsDraft[`${ch}_enabled`]"
                            :disabled="settingsSaving"
                        >
                            {{ channelLabel(ch) }}
                        </el-checkbox>
                    </el-form-item>
                    <el-form-item label="消息模板">
                        <el-input
                            v-model="settingsDraft.message_template"
                            type="textarea"
                            :rows="4"
                            :maxlength="2000"
                            show-word-limit
                            :disabled="settingsSaving"
                            placeholder="留空=用内置多行格式。占位符：{title} {severity} {source} {status} {time} {message}"
                        />
                        <div class="muted inline-help">
                            自定义文本通道（Telegram / 邮件 / 钉钉 / 飞书）通知文案；Webhook 仍为结构化 JSON。留空恢复默认。
                        </div>
                    </el-form-item>
                    <el-form-item label="每通道模板">
                        <el-collapse class="channel-templates">
                            <el-collapse-item v-for="ch in textChannelKeys" :key="ch" :name="ch" :title="`${channelLabel(ch)} 专属模板`">
                                <el-input
                                    v-model="settingsDraft[`message_template_${ch}`]"
                                    type="textarea"
                                    :rows="3"
                                    :maxlength="2000"
                                    show-word-limit
                                    :disabled="settingsSaving"
                                    placeholder="留空=回退上面的全局模板。占位符同上。"
                                />
                            </el-collapse-item>
                        </el-collapse>
                        <div class="muted inline-help">
                            为单个通道单独定制文案；留空则回退全局模板、再回退内置。
                        </div>
                    </el-form-item>
                    <el-button type="primary" :loading="settingsSaving" @click="handleSaveSettings">
                        保存策略
                    </el-button>
                </el-form>
            </div>
        </el-card>

        <el-card shadow="never" class="evaluation-card">
            <div class="notification-header">
                <div>
                    <div class="panel-title">巡检状态</div>
                    <div class="panel-subtitle">最近一次告警评估执行结果</div>
                </div>

                <el-button text :loading="evaluationLoading" @click="loadEvaluationStatus">
                    刷新状态
                </el-button>
            </div>

            <el-empty v-if="!latestEvaluation && !evaluationLoading" description="暂无评估记录" />
            <div v-else-if="latestEvaluation" class="evaluation-grid">
                <el-tag :type="latestEvaluation.status === 'success' ? 'success' : 'danger'" effect="plain">
                    {{ latestEvaluation.status }}
                </el-tag>
                <span>触发：{{ latestEvaluation.trigger }}</span>
                <span>命中：{{ latestEvaluation.detected_count }}</span>
                <span>自动恢复：{{ latestEvaluation.auto_resolved_count }}</span>
                <span>耗时：{{ latestEvaluation.duration_ms }}ms</span>
                <span>完成：{{ latestEvaluation.finished_at || '-' }}</span>
                <span v-if="latestEvaluation.message" class="muted">{{ latestEvaluation.message }}</span>
            </div>
        </el-card>

        <el-card shadow="never" class="rule-card">
            <template #header>
                <div class="panel-header">
                    <div>
                        <div class="panel-title">规则配置</div>
                        <div class="panel-subtitle">系统白名单规则的阈值与启停状态</div>
                    </div>

                    <el-space>
                        <el-button text @click="handleExportRules">导出规则</el-button>
                        <el-button text :loading="importingRules" @click="handleImportRules">导入规则</el-button>
                        <el-button text :loading="rulesLoading" @click="loadAlertRules">
                            刷新规则
                        </el-button>
                    </el-space>
                </div>
            </template>

            <el-alert
                v-if="rulesNotice"
                class="rule-notice"
                :title="rulesNotice"
                type="warning"
                show-icon
                :closable="false"
            />

            <el-table :data="alertRules" border stripe v-loading="rulesLoading" empty-text="暂无告警规则">
                <el-table-column label="规则" min-width="220">
                    <template #default="{ row }">
                        <div class="alert-title">{{ row.name }}</div>
                        <div class="alert-message">{{ row.description }}</div>
                    </template>
                </el-table-column>

                <el-table-column prop="source" label="来源" width="110" />
                <el-table-column prop="metric" label="指标" width="150" />

                <el-table-column label="预警阈值" width="180">
                    <template #default="{ row }">
                        <el-input-number
                            v-model="ruleDrafts[row.key].warning_threshold"
                            :min="row.min"
                            :max="row.max"
                            :precision="thresholdPrecision(row.unit)"
                            controls-position="right"
                            :disabled="savingRuleKey === row.key"
                        />
                    </template>
                </el-table-column>

                <el-table-column label="严重阈值" width="180">
                    <template #default="{ row }">
                        <el-input-number
                            v-if="row.critical_threshold !== null || row.requires_critical"
                            v-model="ruleDrafts[row.key].critical_threshold"
                            :min="row.min"
                            :max="row.max"
                            :precision="thresholdPrecision(row.unit)"
                            controls-position="right"
                            :disabled="savingRuleKey === row.key"
                        />
                        <span v-else class="muted">-</span>
                    </template>
                </el-table-column>

                <el-table-column label="单位" width="90">
                    <template #default="{ row }">
                        {{ row.unit || '-' }}
                    </template>
                </el-table-column>

                <el-table-column label="启用" width="100">
                    <template #default="{ row }">
                        <el-switch
                            v-model="row.is_active"
                            :loading="togglingRuleKey === row.key"
                            :disabled="savingRuleKey === row.key"
                            @change="(value) => handleToggleRule(row, Boolean(value))"
                        />
                    </template>
                </el-table-column>

                <el-table-column label="操作" width="110" fixed="right">
                    <template #default="{ row }">
                        <el-button
                            text
                            type="primary"
                            :loading="savingRuleKey === row.key"
                            :disabled="togglingRuleKey === row.key"
                            @click="handleSaveRule(row)"
                        >
                            保存
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <el-card shadow="never" class="alert-panel">
            <template #header>
                <div class="panel-header">
                    <div>
                        <div class="panel-title">告警中心</div>
                        <div class="panel-subtitle">实时告警、确认处理与通知状态入口</div>
                    </div>

                    <el-space>
                        <el-tag :type="realtimeConnected ? 'success' : 'info'" effect="plain">
                            {{ realtimeConnected ? '实时已连接' : '实时未连接' }}
                        </el-tag>
                        <el-button :loading="testingNotification" @click="handleTestNotification">
                            测试通知
                        </el-button>
                        <el-button :icon="DataLine" :loading="demoLoading" @click="handleDemoScenarios">
                            模拟数据
                        </el-button>
                        <el-button :icon="Refresh" :loading="loading || evaluating" @click="handleEvaluate">
                            立即评估
                        </el-button>
                    </el-space>
                </div>
            </template>

            <div class="filters">
                <el-segmented v-model="status" :options="statusOptions" @change="handleFilterChange" />

                <el-select v-model="severity" clearable placeholder="级别" @change="handleFilterChange">
                    <el-option label="Critical" value="critical" />
                    <el-option label="Warning" value="warning" />
                    <el-option label="Info" value="info" />
                </el-select>

                <el-select v-model="source" clearable placeholder="来源" @change="handleFilterChange">
                    <el-option
                        v-for="item in summary.sources"
                        :key="item.source"
                        :label="item.source"
                        :value="item.source"
                    />
                </el-select>

                <el-select v-model="assigneeFilter" clearable placeholder="指派人" class="assignee-select" @change="handleFilterChange">
                    <el-option label="未指派" value="__unassigned__" />
                    <el-option v-if="currentAdminName" :label="`指派给我（${currentAdminName}）`" :value="currentAdminName" />
                    <el-option
                        v-for="name in assignees.filter(n => n !== currentAdminName)"
                        :key="name"
                        :label="name"
                        :value="name"
                    />
                </el-select>

                <el-select v-model="selectedPresetId" clearable placeholder="筛选预设" class="preset-select" @change="applyPreset">
                    <el-option v-for="p in presets" :key="p.id" :label="p.name" :value="p.id" />
                </el-select>
                <el-button @click="handleSavePreset">保存筛选</el-button>
                <el-button v-if="selectedPresetId" text type="danger" @click="handleDeletePreset">删除预设</el-button>
            </div>

            <el-table :data="alerts" border stripe v-loading="loading" empty-text="暂无告警">
                <el-table-column label="级别" width="120">
                    <template #default="{ row }">
                        <el-tag :type="severityTag(row.severity)" effect="light">
                            {{ row.severity }}
                        </el-tag>
                    </template>
                </el-table-column>

                <el-table-column prop="source" label="来源" width="120" />

                <el-table-column label="告警内容" min-width="360">
                    <template #default="{ row }">
                        <div class="alert-title">{{ row.title }}</div>
                        <div class="alert-message">{{ row.message }}</div>
                    </template>
                </el-table-column>

                <el-table-column label="状态" width="150">
                    <template #default="{ row }">
                        <el-tag :type="row.status === 'open' ? 'danger' : 'info'" effect="plain">
                            {{ statusLabel(row.status) }}
                        </el-tag>
                        <el-tag v-if="row.escalated_at" type="danger" size="small" effect="dark" class="escalated-tag">
                            已升级
                        </el-tag>
                    </template>
                </el-table-column>

                <el-table-column label="指派" width="130">
                    <template #default="{ row }">
                        <span v-if="row.assigned_to">{{ row.assigned_to }}</span>
                        <span v-else class="muted">—</span>
                    </template>
                </el-table-column>

                <el-table-column prop="hit_count" label="次数" width="90" />
                <el-table-column prop="last_seen_at" label="最后出现" width="180" />

                <el-table-column label="操作" width="250" fixed="right">
                    <template #default="{ row }">
                        <div v-if="row.status !== 'resolved'" class="action-buttons">
                            <el-button
                                text
                                type="info"
                                :loading="assigningId === row.id"
                                @click="handleAssign(row)"
                            >
                                指派
                            </el-button>
                            <el-button
                                v-if="currentAdminName"
                                text
                                type="info"
                                :loading="assigningId === row.id"
                                @click="handleClaim(row)"
                            >
                                指派给我
                            </el-button>
                            <el-button
                                v-if="row.status === 'open'"
                                text
                                type="primary"
                                :loading="acknowledgingId === row.id"
                                @click="handleAcknowledge(row)"
                            >
                                确认
                            </el-button>
                            <el-button
                                text
                                type="success"
                                :loading="resolvingId === row.id"
                                @click="handleResolve(row)"
                            >
                                恢复
                            </el-button>
                        </div>
                        <span v-else class="muted">已恢复</span>
                    </template>
                </el-table-column>
            </el-table>

            <div class="pagination-bar">
                <el-pagination
                    v-model:current-page="page"
                    v-model:page-size="perPage"
                    :page-sizes="[10, 20, 50, 100]"
                    :total="pagination.total"
                    background
                    layout="total, sizes, prev, pager, next, jumper"
                    @current-change="loadAlerts"
                    @size-change="handlePageSizeChange"
                />
            </div>
        </el-card>
    </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { DataLine, Refresh } from '@element-plus/icons-vue'
import * as echarts from 'echarts'
import echo from '@/utils/echo'
import {
    acknowledgeAlert,
    assignAlert,
    createAlertDemoScenarios,
    deleteAlertPreset,
    evaluateAlerts,
    exportAlertRules,
    getAlertAssignees,
    getAlertPresets,
    importAlertRules,
    getAlertSettings,
    getAlertTrend,
    getLatestAlertEvaluation,
    getAlertNotificationStatus,
    getAlertRules,
    getAlerts,
    getAlertSummary,
    resolveAlert,
    runAlertHealthCheck,
    saveAlertPreset,
    testAlertNotification,
    toggleAlertRule,
    updateAlertSettings,
    updateAlertRule,
    type AlertEvaluationStatus,
    type AlertPreset,
    type AlertRule,
    type AlertRealtimePayload,
    type AlertNotificationStatus,
    type ChannelStatus,
    type AlertSettings,
    type AlertSeverity,
    type AlertSummary,
    type AlertStatus,
    type OpsAlert,
    type AlertRuleExportItem,
} from '@/api/opsStage4'
import { getAlertSilences } from '@/api/opsAlertSilence'
import { useAdminAuthStore } from '@/stores/adminAuth'

const adminAuth = useAdminAuthStore()
const currentAdminName = computed(() => adminAuth.profile?.admin?.name ?? '')

const loading = ref(false)
const evaluating = ref(false)
const testingNotification = ref(false)
const trendRef = ref<HTMLDivElement>()
const trendDays = ref(14)
const trendLoading = ref(false)
const trendEmpty = ref(false)
let trendChart: echarts.ECharts | null = null
const demoLoading = ref(false)
const notificationLoading = ref(false)
const activeSilenceCount = ref(0)

const loadSilences = async () => {
    try {
        const res = await getAlertSilences()
        activeSilenceCount.value = res.data.data.active.length
    } catch {
        activeSilenceCount.value = 0
    }
}
const runningHealthCheck = ref(false)
const importingRules = ref(false)
const assigneeFilter = ref('')
const assignees = ref<string[]>([])
const rulesLoading = ref(false)
const rulesNotice = ref('')
const evaluationLoading = ref(false)
const settingsSaving = ref(false)
const realtimeConnected = ref(false)
const acknowledgingId = ref<number | null>(null)
const resolvingId = ref<number | null>(null)
const assigningId = ref<number | null>(null)
const savingRuleKey = ref<string | null>(null)
const togglingRuleKey = ref<string | null>(null)
const alerts = ref<OpsAlert[]>([])
const alertRules = ref<AlertRule[]>([])
const latestEvaluation = ref<AlertEvaluationStatus | null>(null)
const settingsDraft = ref<AlertSettings | null>(null)
const ruleDrafts = ref<Record<string, {
    warning_threshold: number
    critical_threshold: number | null
    is_active: boolean
}>>({})
const status = ref<AlertStatus | 'all'>('open')
const severity = ref('')
const source = ref('')
const presets = ref<AlertPreset[]>([])
const selectedPresetId = ref<number | undefined>(undefined)
const page = ref(1)
const perPage = ref(20)
const pagination = ref({
    current_page: 1,
    per_page: 20,
    total: 0,
    last_page: 1,
})
const summary = ref<AlertSummary>({
    open_total: 0,
    critical: 0,
    warning: 0,
    info: 0,
    sources: [],
    checked_at: '-',
})
const notificationStatus = ref<AlertNotificationStatus>({
    telegram: {
        enabled: false,
        configured: false,
        missing: ['enabled'],
    },
    mail: {
        enabled: false,
        configured: false,
        missing: ['enabled'],
    },
    checked_at: '-',
})
let channel: any = null

const statusOptions = [
    { label: 'Open', value: 'open' },
    { label: '已确认', value: 'acknowledged' },
    { label: '全部', value: 'all' },
]
const severityLevels: AlertSeverity[] = ['critical', 'warning', 'info']

const summaryCards = computed(() => [
    {
        label: 'Open',
        value: summary.value.open_total,
        description: '当前未处理告警',
        className: 'open',
    },
    {
        label: 'Critical',
        value: summary.value.critical,
        description: '需要立即关注',
        className: 'critical',
    },
    {
        label: 'Warning',
        value: summary.value.warning,
        description: '达到预警阈值',
        className: 'warning',
    },
    {
        label: 'Info',
        value: summary.value.info,
        description: '提示类事件',
        className: 'info',
    },
])

const CHANNEL_LABELS: Record<string, string> = {
    telegram: 'Telegram',
    mail: '邮件',
    webhook: 'Webhook',
    dingtalk: '钉钉',
    feishu: '飞书',
}

const channelLabel = (name: string): string => CHANNEL_LABELS[name] ?? name

// 通道列表来自后端通知状态（单一来源），前端不再写死 telegram/mail。
const channelKeys = computed<string[]>(() =>
    Object.keys(notificationStatus.value).filter(key => key !== 'checked_at' && key !== 'settings'),
)

// 支持自定义模板的文本通道（去掉 webhook——结构化载荷不套模板）。
const textChannelKeys = computed<string[]>(() => channelKeys.value.filter(name => name !== 'webhook'))

const notificationChannels = computed(() =>
    channelKeys.value.map(name => ({
        name,
        label: channelLabel(name),
        ...(notificationStatus.value[name] as ChannelStatus),
    })),
)

/**
 * 加载告警规则配置。
 */
const loadAlertRules = async () => {
    rulesLoading.value = true
    rulesNotice.value = ''

    try {
        const res = await getAlertRules()
        ruleDrafts.value = Object.fromEntries(
            res.data.data.items.map(rule => [
                rule.key,
                {
                    warning_threshold: rule.warning_threshold,
                    critical_threshold: rule.critical_threshold,
                    is_active: rule.is_active,
                },
            ]),
        )
        alertRules.value = res.data.data.items

        if (alertRules.value.length === 0) {
            rulesNotice.value = '暂无告警规则；执行迁移后刷新规则或触发一次告警评估会同步默认规则。'
        }
    } catch {
        rulesNotice.value = '告警规则加载失败，请检查登录状态、权限或接口状态后重试。'
        ElMessage.error('告警规则加载失败')
    } finally {
        rulesLoading.value = false
    }
}

/**
 * 加载告警汇总。
 */
const loadSummary = async () => {
    const res = await getAlertSummary()
    summary.value = res.data.data
}

/**
 * 加载通知通道配置状态。
 */
const loadNotificationStatus = async () => {
    notificationLoading.value = true

    try {
        const res = await getAlertNotificationStatus()
        notificationStatus.value = res.data.data
        settingsDraft.value = res.data.data.settings ? structuredClone(res.data.data.settings) : settingsDraft.value
    } catch {
        ElMessage.error('通知通道状态加载失败')
    } finally {
        notificationLoading.value = false
    }
}

/**
 * 立即对已启用通道做一次连通性自检并刷新状态。
 */
const handleHealthCheck = async () => {
    runningHealthCheck.value = true

    try {
        const res = await runAlertHealthCheck()
        notificationStatus.value = res.data.data
        const summary = res.data.data.summary
        if (summary && summary.enabled === false) {
            ElMessage.info('通道健康自检未启用（OPS_ALERT_HEALTH_ENABLED）')
        } else {
            ElMessage.success(`通道自检完成：正常 ${summary?.healthy ?? 0} / 异常 ${summary?.failing ?? 0}`)
        }
    } catch {
        ElMessage.error('通道健康自检失败')
    } finally {
        runningHealthCheck.value = false
    }
}

const loadAlertSettings = async () => {
    const res = await getAlertSettings()
    settingsDraft.value = structuredClone(res.data.data)
}

const loadEvaluationStatus = async () => {
    evaluationLoading.value = true

    try {
        const res = await getLatestAlertEvaluation()
        latestEvaluation.value = res.data.data
    } catch {
        ElMessage.error('巡检状态加载失败')
    } finally {
        evaluationLoading.value = false
    }
}

/**
 * 加载告警列表。
 */
const loadAlerts = async () => {
    loading.value = true

    try {
        const res = await getAlerts({
            status: status.value === 'all' ? undefined : status.value,
            severity: severity.value || undefined,
            source: source.value || undefined,
            assigned: assigneeFilter.value === '__unassigned__' ? 'unassigned' : undefined,
            assigned_to: assigneeFilter.value && assigneeFilter.value !== '__unassigned__' ? assigneeFilter.value : undefined,
            page: page.value,
            per_page: perPage.value,
        })

        alerts.value = res.data.data.items
        pagination.value = res.data.data.pagination
        page.value = pagination.value.current_page
        perPage.value = pagination.value.per_page
    } catch {
        ElMessage.error('告警列表加载失败')
    } finally {
        loading.value = false
    }
}

/**
 * 手动执行一次告警评估。
 */
const handleEvaluate = async () => {
    evaluating.value = true

    try {
        const res = await evaluateAlerts()
        summary.value = res.data.data.summary
        await Promise.all([loadAlerts(), loadEvaluationStatus()])
        emitAlertStateChanged()
        ElMessage.success(`评估完成，命中 ${res.data.data.detected} 条规则，自动恢复 ${res.data.data.auto_resolved ?? 0} 条`)
    } catch {
        ElMessage.error('告警评估失败')
    } finally {
        evaluating.value = false
    }
}

const handleSaveSettings = async () => {
    if (!settingsDraft.value) {
        return
    }

    settingsSaving.value = true

    try {
        const res = await updateAlertSettings(settingsDraft.value)
        settingsDraft.value = structuredClone(res.data.data)
        await loadNotificationStatus()
        ElMessage.success('通知策略已保存')
    } catch {
        ElMessage.error('通知策略保存失败，请检查参数范围')
    } finally {
        settingsSaving.value = false
    }
}

/**
 * 测试 Telegram / 邮件通知通道。
 */
const handleTestNotification = async () => {
    testingNotification.value = true

    try {
        const res = await testAlertNotification({
            channels: channelKeys.value,
            message: 'Ops Center 告警中心通知通道测试。',
        })
        const result = res.data.data.result
        const sentChannels = Object.entries(result)
            .filter(([, item]) => item.sent)
            .map(([channel]) => channel)

        if (sentChannels.length > 0) {
            ElMessage.success(`通知测试成功：${sentChannels.join(', ')}`)
            return
        }

        ElMessage.warning('通知测试未发送，请检查 Telegram / 邮件配置是否启用')
    } catch {
        ElMessage.error('通知测试失败')
    } finally {
        testingNotification.value = false
    }
}

/**
 * 生成一组接近真实值班流程的演示告警。
 */
const handleDemoScenarios = async () => {
    demoLoading.value = true

    try {
        const res = await createAlertDemoScenarios()
        const result = res.data.data

        if (!result.enabled) {
            ElMessage.warning('模拟数据入口未启用，请检查 OPS_ALERT_DEMO_ENABLED')
            return
        }

        summary.value = result.summary
        status.value = 'all'
        page.value = 1
        await loadAlerts()
        emitAlertStateChanged()
        ElMessage.success(`已生成 ${result.created} 条模拟告警，覆盖触发、确认与恢复流程`)
    } catch {
        ElMessage.error('模拟告警生成失败')
    } finally {
        demoLoading.value = false
    }
}

/**
 * 保存单条规则阈值。
 */
const handleSaveRule = async (rule: AlertRule) => {
    const draft = ruleDrafts.value[rule.key]

    if (!draft) {
        ElMessage.error('规则草稿不存在，请刷新后重试')
        return
    }

    savingRuleKey.value = rule.key

    try {
        const res = await updateAlertRule(rule.key, {
            warning_threshold: draft.warning_threshold,
            critical_threshold: draft.critical_threshold,
            is_active: rule.is_active,
        })
        replaceRule(res.data.data)
        await Promise.all([loadAlertRules(), loadSummary()])
        ElMessage.success('告警规则已保存')
    } catch {
        ElMessage.error('告警规则保存失败，请检查阈值范围')
    } finally {
        savingRuleKey.value = null
    }
}

/**
 * 启用或禁用单条规则。
 */
const handleToggleRule = async (rule: AlertRule, isActive: boolean) => {
    togglingRuleKey.value = rule.key

    try {
        const res = await toggleAlertRule(rule.key, isActive)
        replaceRule(res.data.data)
        await Promise.all([loadAlertRules(), loadSummary()])
        ElMessage.success(isActive ? '告警规则已启用' : '告警规则已禁用')
    } catch {
        rule.is_active = !isActive
        ElMessage.error('告警规则状态更新失败')
    } finally {
        togglingRuleKey.value = null
    }
}

/**
 * 用接口返回值替换页面中的规则。
 */
const replaceRule = (rule: AlertRule) => {
    alertRules.value = alertRules.value.map(item => item.key === rule.key ? rule : item)
    ruleDrafts.value[rule.key] = {
        warning_threshold: rule.warning_threshold,
        critical_threshold: rule.critical_threshold,
        is_active: rule.is_active,
    }
}

/**
 * 导出全部规则的可调字段为 JSON 文件。
 */
const handleExportRules = async () => {
    try {
        const res = await exportAlertRules()
        const blob = new Blob([JSON.stringify(res.data.data, null, 2)], { type: 'application/json' })
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `ops-alert-rules-${Date.now()}.json`
        link.click()
        URL.revokeObjectURL(url)
        ElMessage.success('已导出规则 JSON')
    } catch {
        ElMessage.error('规则导出失败')
    }
}

/**
 * 粘贴规则 JSON 导入（仅认白名单 key 的阈值/启停，非法项跳过并报告）。
 */
const handleImportRules = async () => {
    let text = ''

    try {
        const { value } = await ElMessageBox.prompt(
            '粘贴导出的规则 JSON（含 rules 数组，或直接是规则数组）',
            '导入规则',
            {
                confirmButtonText: '导入',
                cancelButtonText: '取消',
                inputType: 'textarea',
                inputPlaceholder: '{ "rules": [ { "key": "disk_usage", "warning_threshold": 85, "critical_threshold": 95, "is_active": true } ] }',
            },
        )
        text = value
    } catch {
        return
    }

    let rules: AlertRuleExportItem[]

    try {
        const parsed = JSON.parse(text)
        rules = Array.isArray(parsed) ? parsed : parsed.rules
        if (!Array.isArray(rules)) {
            throw new Error('missing rules array')
        }
    } catch {
        ElMessage.error('JSON 解析失败，请检查格式')
        return
    }

    importingRules.value = true

    try {
        const res = await importAlertRules(rules)
        const { applied, total, skipped } = res.data.data
        await Promise.all([loadAlertRules(), loadSummary()])

        if (skipped.length > 0) {
            ElMessage.warning(`导入完成：应用 ${applied}/${total}，跳过 ${skipped.length}（未知或越界的规则）`)
        } else {
            ElMessage.success(`导入完成：应用 ${applied}/${total} 条规则`)
        }
    } catch {
        ElMessage.error('规则导入失败，请检查权限或数据结构')
    } finally {
        importingRules.value = false
    }
}

/**
 * 筛选条件变化后回到第一页。
 */
const handleFilterChange = async () => {
    page.value = 1
    await loadAlerts()
}

const loadPresets = async () => {
    try {
        const res = await getAlertPresets()
        presets.value = res.data.data.items
    } catch {
        presets.value = []
    }
}

const applyPreset = async (id: number | undefined) => {
    const preset = presets.value.find(p => p.id === id)
    if (!preset) return
    status.value = (preset.filters.status as AlertStatus) ?? 'all'
    severity.value = preset.filters.severity ?? ''
    source.value = preset.filters.source ?? ''
    await handleFilterChange()
}

const handleSavePreset = async () => {
    try {
        const { value } = await ElMessageBox.prompt('为当前筛选取个名字', '保存筛选预设', {
            confirmButtonText: '保存',
            cancelButtonText: '取消',
            inputPattern: /\S+/,
            inputErrorMessage: '名称不能为空',
        })
        const filters: Record<string, string> = {}
        if (status.value && status.value !== 'all') filters.status = status.value
        if (severity.value) filters.severity = severity.value
        if (source.value) filters.source = source.value
        await saveAlertPreset({ name: value.trim(), filters })
        ElMessage.success('已保存预设')
        await loadPresets()
    } catch {
        // 取消或校验失败
    }
}

const handleDeletePreset = async () => {
    if (!selectedPresetId.value) return
    try {
        await ElMessageBox.confirm('删除后不可恢复。', '删除预设', {
            type: 'warning',
            confirmButtonText: '删除',
            cancelButtonText: '取消',
        })
    } catch {
        return
    }
    await deleteAlertPreset(selectedPresetId.value)
    selectedPresetId.value = undefined
    ElMessage.success('已删除')
    await loadPresets()
}

/**
 * 每页条数变化后回到第一页。
 */
const handlePageSizeChange = async () => {
    page.value = 1
    await loadAlerts()
}

/**
 * 确认告警。
 */
const handleAcknowledge = async (alert: OpsAlert) => {
    try {
        const { value } = await ElMessageBox.prompt('填写确认备注，可留空', '确认告警', {
            confirmButtonText: '确认',
            cancelButtonText: '取消',
            inputPlaceholder: '例如：已处理、观察中',
        })

        acknowledgingId.value = alert.id
        await acknowledgeAlert(alert.id, {
            acknowledged_by: 'ops-user',
            note: value,
        })

        ElMessage.success('告警已确认')
        emitAlertStateChanged()
        await Promise.all([loadSummary(), loadAlerts()])
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('告警确认失败')
        }
    } finally {
        acknowledgingId.value = null
    }
}

const handleAssign = async (alert: OpsAlert) => {
    try {
        const { value } = await ElMessageBox.prompt('填写负责人', '指派告警', {
            confirmButtonText: '指派',
            cancelButtonText: '取消',
            inputPlaceholder: '例如：on-call-a',
            inputPattern: /^[\p{L}\p{N}@._\-\s]+$/u,
            inputErrorMessage: '负责人只能包含文字、数字、空格、@ . _ -',
        })

        assigningId.value = alert.id
        await assignAlert(alert.id, {
            assigned_to: value,
            note: `指派给 ${value}`,
        })

        ElMessage.success('告警已指派')
        await Promise.all([loadAlerts(), loadAssignees()])
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('告警指派失败')
        }
    } finally {
        assigningId.value = null
    }
}

/**
 * 把告警认领给当前登录管理员（指派给我）。
 */
const handleClaim = async (alert: OpsAlert) => {
    if (!currentAdminName.value) {
        return
    }

    assigningId.value = alert.id

    try {
        await assignAlert(alert.id, {
            assigned_to: currentAdminName.value,
            note: `认领：${currentAdminName.value}`,
        })
        ElMessage.success('已认领该告警')
        await Promise.all([loadAlerts(), loadAssignees()])
    } catch {
        ElMessage.error('认领失败')
    } finally {
        assigningId.value = null
    }
}

/**
 * 加载已指派处理人列表（筛选下拉用）。
 */
const loadAssignees = async () => {
    try {
        const res = await getAlertAssignees()
        assignees.value = res.data.data.items
    } catch {
        assignees.value = []
    }
}

/**
 * 标记告警已恢复。
 */
const handleResolve = async (alert: OpsAlert) => {
    try {
        await ElMessageBox.confirm('确认该告警已恢复？', '恢复告警', {
            confirmButtonText: '确认恢复',
            cancelButtonText: '取消',
            type: 'success',
        })

        resolvingId.value = alert.id
        await resolveAlert(alert.id, {
            acknowledged_by: 'ops-user',
            note: '已恢复',
        })

        ElMessage.success('告警已恢复')
        emitAlertStateChanged()
        await Promise.all([loadSummary(), loadAlerts()])
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('告警恢复失败')
        }
    } finally {
        resolvingId.value = null
    }
}

/**
 * 通知布局层刷新告警徽标。
 */
const emitAlertStateChanged = () => {
    window.dispatchEvent(new CustomEvent('ops:alerts-updated'))
}

/**
 * 建立告警 WebSocket 监听。
 */
const startRealtime = () => {
    if (channel) {
        return
    }

    channel = echo.channel('ops.alerts')
        .listen('.alert.triggered', async (payload: AlertRealtimePayload) => {
            realtimeConnected.value = true
            ElMessage.warning(`${payload.source}: ${payload.title}`)
            await Promise.all([loadSummary(), loadAlerts()])
        })
        .error(() => {
            realtimeConnected.value = false
        })

    realtimeConnected.value = true
}

/**
 * 离开页面时释放频道。
 */
const stopRealtime = () => {
    if (channel) {
        echo.leaveChannel('ops.alerts')
        channel = null
    }

    realtimeConnected.value = false
}

const severityTag = (value: string) => {
    if (value === 'critical') {
        return 'danger'
    }

    if (value === 'warning') {
        return 'warning'
    }

    return 'info'
}

const statusLabel = (value: string) => {
    if (value === 'open') {
        return '未处理'
    }

    if (value === 'acknowledged') {
        return '已确认'
    }

    return '已恢复'
}

const thresholdPrecision = (unit: string | null) => unit === 'MB/s' ? 2 : 0

const loadTrend = async () => {
    trendLoading.value = true

    try {
        const res = await getAlertTrend(trendDays.value)
        const buckets = res.data.data.buckets
        trendEmpty.value = buckets.every(bucket => bucket.evaluations === 0)

        if (!trendChart && trendRef.value) {
            trendChart = echarts.init(trendRef.value)
        }

        trendChart?.setOption({
            tooltip: { trigger: 'axis' },
            legend: { top: 8, left: 'center' },
            grid: { top: 44, left: 8, right: 16, bottom: 8, containLabel: true },
            xAxis: { type: 'category', boundaryGap: false, data: buckets.map(bucket => bucket.date) },
            yAxis: { type: 'value', minInterval: 1 },
            series: [
                { name: '命中告警', type: 'line', smooth: true, itemStyle: { color: '#dc2626' }, data: buckets.map(bucket => bucket.detected) },
                { name: '自动恢复', type: 'line', smooth: true, itemStyle: { color: '#16a34a' }, data: buckets.map(bucket => bucket.auto_resolved) },
            ],
        })

        trendChart?.resize()
    } finally {
        trendLoading.value = false
    }
}

const handleTrendResize = () => trendChart?.resize()

onMounted(async () => {
    await Promise.all([loadSummary(), loadAlerts(), loadNotificationStatus(), loadAlertRules(), loadAlertSettings(), loadEvaluationStatus(), loadTrend(), loadSilences(), loadPresets(), loadAssignees()])
    startRealtime()
    window.addEventListener('resize', handleTrendResize)
})

onBeforeUnmount(() => {
    window.removeEventListener('resize', handleTrendResize)
    stopRealtime()
    trendChart?.dispose()
    trendChart = null
})
</script>

<style scoped>
.alerts-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.trend-days {
    width: 130px;
}

.trend-chart {
    height: 280px;
    min-height: 240px;
}

.summary-grid {
    display: grid;
    gap: 14px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.notification-card,
.evaluation-card,
.rule-card {
    border-radius: 8px;
}

.notification-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.notification-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-top: 14px;
}

.settings-panel {
    border-top: 1px solid #e5e7eb;
    margin-top: 14px;
    padding-top: 14px;
}

.settings-form {
    margin-top: 10px;
}

.severity-grid,
.evaluation-grid {
    display: grid;
    gap: 8px;
}

.severity-row,
.evaluation-grid {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
}

.severity-row {
    gap: 10px;
}

.severity-label {
    color: #111827;
    font-weight: 700;
    min-width: 70px;
}

.evaluation-grid {
    color: #475569;
    gap: 12px;
    margin-top: 14px;
}

.inline-help {
    margin-left: 8px;
}

.escalated-tag {
    margin-left: 6px;
}

.notification-item {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px;
}

.channel-title {
    align-items: center;
    color: #111827;
    display: flex;
    font-weight: 700;
    justify-content: space-between;
}

.channel-desc {
    color: #64748b;
    font-size: 12px;
    line-height: 1.6;
    margin-top: 8px;
}

.rule-notice {
    margin-bottom: 12px;
}

.summary-card {
    border-radius: 8px;
}

.summary-label {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.summary-value {
    color: #111827;
    font-size: 30px;
    font-weight: 800;
    margin-top: 8px;
}

.summary-value.critical {
    color: #dc2626;
}

.summary-value.warning {
    color: #d97706;
}

.summary-value.info {
    color: #2563eb;
}

.summary-value.open {
    color: #111827;
}

.summary-desc {
    color: #94a3b8;
    font-size: 12px;
    margin-top: 4px;
}

.panel-header,
.filters,
.pagination-bar {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.panel-title {
    color: #111827;
    font-size: 18px;
    font-weight: 700;
}

.panel-subtitle {
    color: #64748b;
    font-size: 13px;
    margin-top: 4px;
}

.filters {
    gap: 12px;
    justify-content: flex-start;
    margin-bottom: 14px;
}

.filters :deep(.el-select) {
    width: 160px;
}

.alert-title {
    color: #111827;
    font-weight: 700;
}

.alert-message {
    color: #64748b;
    font-size: 12px;
    line-height: 1.6;
    margin-top: 4px;
}

.muted {
    color: #94a3b8;
    font-size: 12px;
}

.action-buttons {
    align-items: center;
    display: flex;
    gap: 6px;
}

.action-buttons :deep(.el-button + .el-button) {
    margin-left: 0;
}

.pagination-bar {
    justify-content: flex-end;
    padding-top: 14px;
}

@media (max-width: 1100px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 760px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .notification-grid {
        grid-template-columns: 1fr;
    }

    .panel-header,
    .filters {
        align-items: stretch;
        flex-direction: column;
    }

    .filters :deep(.el-select) {
        width: 100%;
    }
}
</style>
