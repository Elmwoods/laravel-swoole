<template>
    <section class="logs-page">
        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <div>
                        <div class="title">日志中心</div>
                        <div class="subtitle">{{ currentDescription }}</div>
                    </div>

                    <el-space>
                        <el-switch
                            v-model="autoRefresh"
                            active-text="自动刷新"
                            inactive-text="手动"
                        />
                        <el-button :icon="Refresh" :loading="loading" @click="loadLogs">
                            刷新
                        </el-button>
                    </el-space>
                </div>
            </template>

            <div class="toolbar">
                <el-tabs v-model="active" class="tabs" @tab-change="handleTabChange">
                    <el-tab-pane label="Laravel" name="laravel" />
                    <el-tab-pane label="Octane" name="octane" />
                    <el-tab-pane label="Redis SlowLog" name="redis" />
                    <el-tab-pane label="System" name="system" />
                    <el-tab-pane label="Docker" name="docker" />
                </el-tabs>

                <div class="filters">
                    <el-select
                        v-if="active === 'system'"
                        v-model="systemSource"
                        class="source-select"
                        placeholder="系统日志来源"
                        @change="handleQueryChange"
                    >
                        <el-option
                            v-for="source in systemSources"
                            :key="source.key"
                            :label="source.key"
                            :value="source.key"
                        >
                            <span>{{ source.key }}</span>
                            <el-tag
                                class="source-state"
                                size="small"
                                :type="source.exists && source.readable ? 'success' : 'warning'"
                            >
                                {{ source.exists && source.readable ? '可读' : '不可读' }}
                            </el-tag>
                        </el-option>
                    </el-select>

                    <el-input
                        v-model="keyword"
                        class="keyword-input"
                        clearable
                        placeholder="关键词过滤"
                        @keyup.enter="handleQueryChange"
                        @clear="handleQueryChange"
                    />

                    <el-input
                        v-if="active === 'docker'"
                        v-model="dockerContainer"
                        class="source-select"
                        clearable
                        placeholder="容器名称或 ID"
                        @keyup.enter="handleQueryChange"
                        @clear="handleQueryChange"
                    />

                    <el-date-picker
                        v-model="timeRange"
                        class="time-range"
                        type="datetimerange"
                        unlink-panels
                        range-separator="至"
                        start-placeholder="开始时间"
                        end-placeholder="结束时间"
                        value-format="YYYY-MM-DD HH:mm:ss"
                        @change="handleQueryChange"
                    />

                    <el-input-number
                        v-model="lines"
                        :min="10"
                        :max="1000"
                        :step="50"
                        aria-label="Tail 行数"
                        controls-position="right"
                        @change="handleQueryChange"
                    />
                </div>
            </div>

            <el-alert
                v-if="notice"
                class="notice"
                :title="notice"
                type="warning"
                show-icon
                :closable="false"
            />

            <div class="drilldown-shell">
                <aside class="drilldown-sidebar">
                    <div class="panel-title">Sources</div>
                    <button
                        v-for="source in sourceCards"
                        :key="source.name"
                        class="source-card"
                        :class="{ active: active === source.name }"
                        type="button"
                        @click="switchSource(source.name)"
                    >
                        <span>{{ source.label }}</span>
                        <strong>{{ source.total }}</strong>
                    </button>

                    <div v-if="active !== 'redis'" class="facet-block">
                        <div class="panel-title">Level</div>
                        <button
                            class="facet-row"
                            :class="{ selected: selectedLevel === '' }"
                            type="button"
                            @click="selectLevel('')"
                        >
                            <span>ALL</span>
                            <strong>{{ facetTotal }}</strong>
                        </button>
                        <button
                            v-for="level in levelFacets"
                            :key="level.name"
                            class="facet-row"
                            :class="{ selected: selectedLevel === level.name }"
                            type="button"
                            @click="selectLevel(level.name)"
                        >
                            <span class="level-name">
                                <i :class="['level-dot', level.name.toLowerCase()]" />
                                {{ level.name }}
                            </span>
                            <strong>{{ level.total }}</strong>
                        </button>
                    </div>

                    <div v-if="active !== 'redis'" class="facet-block">
                        <div class="panel-title">Timeline</div>
                        <div v-if="timelineFacets.length" class="timeline-list">
                            <div v-for="item in timelineFacets" :key="item.bucket" class="timeline-row">
                                <span>{{ item.bucket.slice(11) }}</span>
                                <div class="timeline-track">
                                    <div class="timeline-bar" :style="{ width: timelineWidth(item.total) }" />
                                </div>
                                <strong>{{ item.total }}</strong>
                            </div>
                        </div>
                        <el-empty v-else description="暂无时间分布" :image-size="56" />
                    </div>
                </aside>

                <main class="drilldown-main">
                    <div v-if="active === 'redis'" class="slowlog-wrap">
                        <el-table :data="redisEntries" border stripe v-loading="loading" empty-text="暂无 Redis 慢日志">
                            <el-table-column prop="id" label="ID" width="90" />
                            <el-table-column prop="occurred_at" label="时间" width="170" />
                            <el-table-column prop="duration_ms" label="耗时 ms" width="120" />
                            <el-table-column prop="command" label="命令" min-width="360" show-overflow-tooltip />
                            <el-table-column prop="client" label="客户端" min-width="180" show-overflow-tooltip />
                        </el-table>
                        <div class="pagination-bar">
                            <el-pagination
                                v-model:current-page="page"
                                v-model:page-size="perPage"
                                :page-sizes="[10, 20, 50, 100]"
                                :total="redisPagination.total"
                                background
                                layout="total, sizes, prev, pager, next, jumper"
                                @current-change="loadLogs"
                                @size-change="handlePageSizeChange"
                            />
                        </div>
                    </div>

                    <div v-else class="log-panel" v-loading="loading">
                        <div class="log-meta">
                            <span>{{ logResult?.path || '-' }}</span>
                            <span>第 {{ pagination.current_page }} / {{ pagination.last_page }} 页</span>
                            <span>{{ pagination.total }} 条</span>
                            <span>{{ logResult?.count ?? 0 }} 行</span>
                            <span>{{ logResult?.checked_at || '-' }}</span>
                        </div>

                        <div class="view-mode">
                            <el-segmented v-model="viewMode" :options="viewOptions" />
                        </div>

                        <el-scrollbar height="640px" class="log-scroll">
                            <div v-if="viewMode === 'entries' && pagedEntries.length" class="entry-list">
                                <article v-for="(entry, index) in pagedEntries" :key="entryKey(entry, index)" class="log-entry">
                                    <div class="entry-head">
                                        <div class="entry-title">
                                            <el-tag size="small" :type="levelTagType(entry.level)">
                                                {{ entry.level || 'INFO' }}
                                            </el-tag>
                                            <span class="entry-time">{{ entry.time || '-' }}</span>
                                            <span class="entry-summary-inline">
                                                <template v-for="(part, partIndex) in highlightParts(entry.summary || firstLine(entry.content))" :key="partIndex">
                                                    <mark v-if="part.highlight">{{ part.text }}</mark>
                                                    <span v-else>{{ part.text }}</span>
                                                </template>
                                            </span>
                                        </div>
                                        <el-button
                                            v-if="entry.truncated"
                                            text
                                            size="small"
                                            @click="toggleEntry(entryKey(entry, index))"
                                        >
                                            {{ expandedEntries.has(entryKey(entry, index)) ? '收起' : `展开 ${entry.line_count} 行` }}
                                        </el-button>
                                        <span v-else class="entry-lines">{{ entry.line_count || entry.lines.length }} 行</span>
                                    </div>

                                    <div class="entry-content">
                                        <div
                                            v-for="(line, lineIndex) in visibleEntryLines(entry, entryKey(entry, index))"
                                            :key="lineIndex"
                                            class="log-line"
                                            :class="entry.level?.toLowerCase()"
                                        >
                                            <span class="line-marker" />
                                            <code>
                                                <template v-for="(part, partIndex) in highlightParts(line)" :key="partIndex">
                                                    <mark v-if="part.highlight">{{ part.text }}</mark>
                                                    <span v-else>{{ part.text }}</span>
                                                </template>
                                            </code>
                                        </div>
                                    </div>
                                </article>
                            </div>

                            <pre v-else-if="logText" class="log-text"><template v-for="(part, index) in highlightParts(logText)" :key="index"><mark v-if="part.highlight">{{ part.text }}</mark><span v-else>{{ part.text }}</span></template></pre>
                            <el-empty v-else description="暂无日志内容" />
                        </el-scrollbar>

                        <div class="pagination-bar">
                            <el-pagination
                                v-model:current-page="page"
                                v-model:page-size="perPage"
                                :page-sizes="[10, 20, 50, 100]"
                                :total="pagination.total"
                                background
                                layout="total, sizes, prev, pager, next, jumper"
                                @current-change="loadLogs"
                                @size-change="handlePageSizeChange"
                            />
                        </div>
                    </div>
                </main>
            </div>
        </el-card>
    </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import {
    getLaravelLogs,
    getOctaneLogs,
    getRedisSlowLogs,
    getSystemLogs,
    getSystemLogSources,
    getDockerLogs,
    type LogFileResult,
    type LogEntry,
    type RedisSlowLogEntry,
    type SystemLogSource,
} from '@/api/opsStage3'

type LogTab = 'laravel' | 'octane' | 'redis' | 'system' | 'docker'

const active = ref<LogTab>('laravel')
const loading = ref(false)
const autoRefresh = ref(true)
const keyword = ref('')
const lines = ref(200)
const page = ref(1)
const perPage = ref(20)
const notice = ref('')
const systemSource = ref('')
const dockerContainer = ref('')
const timeRange = ref<[string, string] | []>([])
const systemSources = ref<SystemLogSource[]>([])
const logResult = ref<LogFileResult | null>(null)
const redisEntries = ref<RedisSlowLogEntry[]>([])
const redisPagination = ref({
    current_page: 1,
    per_page: 20,
    total: 0,
    last_page: 1,
    has_more: false,
})
const viewMode = ref<'entries' | 'raw'>('entries')
const selectedLevel = ref('')
const expandedEntries = ref(new Set<string>())
let timer: number | null = null

const descriptions: Record<LogTab, string> = {
    laravel: 'Laravel 应用日志，支持关键词过滤和自动刷新',
    octane: 'Octane / Swoole 运行日志，用于排查 Worker 与请求异常',
    redis: 'Redis SLOWLOG 慢查询记录，用于定位慢命令',
    system: '容器内系统与 Supervisor 相关日志，来源受后端白名单保护',
    docker: 'Docker 容器日志，容器标识与读取行数均受后端边界控制',
}

const currentDescription = computed(() => descriptions[active.value])
const logText = computed(() => (logResult.value?.lines ?? []).join('\n'))
const logEntries = computed<LogEntry[]>(() => logResult.value?.entries ?? [])
const pagedEntries = computed(() => logEntries.value)
const levelFacets = computed(() => logResult.value?.facets?.levels ?? [])
const facetTotal = computed(() => levelFacets.value.reduce((total, item) => total + item.total, 0))
const timelineFacets = computed(() => logResult.value?.facets?.timeline ?? [])
const pagination = computed(() => logResult.value?.pagination ?? {
    current_page: page.value,
    per_page: perPage.value,
    total: 0,
    last_page: 1,
    has_more: false,
})
const maxTimelineTotal = computed(() => Math.max(...timelineFacets.value.map(item => item.total), 1))
const sourceCards = computed(() => [
    { name: 'laravel' as const, label: 'Laravel', total: active.value === 'laravel' ? pagination.value.total : '-' },
    { name: 'octane' as const, label: 'Octane', total: active.value === 'octane' ? pagination.value.total : '-' },
    { name: 'redis' as const, label: 'Redis SlowLog', total: active.value === 'redis' ? redisPagination.value.total : '-' },
    { name: 'system' as const, label: 'System', total: active.value === 'system' ? pagination.value.total : '-' },
    { name: 'docker' as const, label: 'Docker', total: active.value === 'docker' ? pagination.value.total : '-' },
])
const viewOptions = [
    { label: '事件', value: 'entries' },
    { label: '原文', value: 'raw' },
]

/**
 * 关键词高亮分段。
 *
 * 这里返回纯文本片段，模板中用文本插值渲染，不使用 HTML 注入，
 * 避免日志内容里出现 HTML 时造成脚本注入。
 */
const highlightParts = (text: string) => {
    const keywordValue = keyword.value.trim()

    if (!keywordValue) {
        return [{ text, highlight: false }]
    }

    const lowerText = text.toLowerCase()
    const lowerKeyword = keywordValue.toLowerCase()
    const parts: Array<{ text: string; highlight: boolean }> = []
    let cursor = 0
    let index = lowerText.indexOf(lowerKeyword)

    while (index !== -1) {
        if (index > cursor) {
            parts.push({ text: text.slice(cursor, index), highlight: false })
        }

        parts.push({
            text: text.slice(index, index + keywordValue.length),
            highlight: true,
        })

        cursor = index + keywordValue.length
        index = lowerText.indexOf(lowerKeyword, cursor)
    }

    if (cursor < text.length) {
        parts.push({ text: text.slice(cursor), highlight: false })
    }

    return parts.length ? parts : [{ text, highlight: false }]
}

/**
 * 获取日志内容第一行作为兜底摘要。
 */
const firstLine = (text: string) => text.split(/\r?\n/)[0] || ''

/**
 * 日志事件稳定 key。
 *
 * 展开状态需要跨自动刷新保留，所以不能使用列表下标作为唯一依据。
 * 这里把日志来源、时间、级别和摘要压成短 hash，同一条日志刷新后仍能匹配。
 */
const entryKey = (entry: LogEntry, _index: number) => {
    const rawKey = [
        active.value,
        entry.time || 'no-time',
        entry.level || 'INFO',
        entry.summary || firstLine(entry.content || ''),
    ].join('|')

    return `log-${hashText(rawKey)}`
}

/**
 * 生成轻量字符串 hash。
 *
 * 前端只需要稳定 UI key，不涉及加密场景，因此使用简单整数 hash 即可。
 */
const hashText = (text: string) => {
    let hash = 0

    for (let index = 0; index < text.length; index += 1) {
        hash = ((hash << 5) - hash) + text.charCodeAt(index)
        hash |= 0
    }

    return Math.abs(hash).toString(36)
}

/**
 * 展开或收起单条日志。
 */
const toggleEntry = (key: string) => {
    const next = new Set(expandedEntries.value)

    if (next.has(key)) {
        next.delete(key)
    } else {
        next.add(key)
    }

    expandedEntries.value = next
}

/**
 * 获取当前应展示的日志行。
 */
const visibleEntryLines = (entry: LogEntry, key: string) => {
    if (expandedEntries.value.has(key)) {
        return entry.lines
    }

    return entry.preview_lines?.length ? entry.preview_lines : entry.lines
}

/**
 * 根据日志级别返回 Element Plus 标签类型。
 */
const levelTagType = (level: string) => {
    const normalized = level.toUpperCase()

    if (['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR'].includes(normalized)) {
        return 'danger'
    }

    if (['WARNING', 'NOTICE'].includes(normalized)) {
        return 'warning'
    }

    if (normalized === 'DEBUG') {
        return 'info'
    }

    return 'success'
}

/**
 * 切换左侧日志源。
 */
const switchSource = async (source: LogTab) => {
    active.value = source
    await handleTabChange()
}

/**
 * 选择日志级别并重新请求第一页。
 */
const selectLevel = async (level: string) => {
    selectedLevel.value = level
    page.value = 1
    await loadLogs()
}

/**
 * 查询条件变化后回到第一页。
 */
const handleQueryChange = async () => {
    page.value = 1
    await loadLogs()
}

/**
 * 每页条数变化后回到第一页。
 */
const handlePageSizeChange = async () => {
    page.value = 1
    await loadLogs()
}

/**
 * 时间桶柱状条宽度。
 */
const timelineWidth = (total: number) => `${Math.max(8, (total / maxTimelineTotal.value) * 100)}%`

/**
 * 加载系统日志来源白名单。
 */
const loadSources = async () => {
    const res = await getSystemLogSources()
    systemSources.value = res.data.data.sources

    if (!systemSource.value && systemSources.value.length > 0) {
        systemSource.value = systemSources.value[0].key
    }
}

/**
 * 根据当前标签加载日志。
 */
const loadLogs = async () => {
    loading.value = true
    notice.value = ''

    try {
        const [from, to] = timeRange.value
        const query = {
            lines: lines.value,
            tail: lines.value,
            page: page.value,
            per_page: perPage.value,
            keyword: keyword.value,
            level: selectedLevel.value,
            from,
            to,
            source: systemSource.value,
            container: dockerContainer.value,
        }

        if (active.value === 'laravel') {
            const res = await getLaravelLogs(query)
            applyFileResult(res.data.data)
            return
        }

        if (active.value === 'octane') {
            const res = await getOctaneLogs(query)
            applyFileResult(res.data.data)
            return
        }

        if (active.value === 'system') {
            const res = await getSystemLogs(query)
            applyFileResult(res.data.data)
            return
        }

        if (active.value === 'docker') {
            if (!dockerContainer.value.trim()) {
                logResult.value = null
                redisEntries.value = []
                notice.value = '请输入 Docker 容器名称或 ID'
                return
            }

            const res = await getDockerLogs(query)
            applyFileResult(res.data.data)
            return
        }

        const res = await getRedisSlowLogs(query)
        redisEntries.value = res.data.data.entries
        redisPagination.value = res.data.data.pagination ?? {
            current_page: page.value,
            per_page: perPage.value,
            total: res.data.data.count,
            last_page: Math.max(1, Math.ceil(res.data.data.count / perPage.value)),
            has_more: false,
        }
        page.value = redisPagination.value.current_page
        perPage.value = redisPagination.value.per_page
        logResult.value = null
        notice.value = res.data.data.available ? '' : (res.data.data.message || 'Redis 慢日志不可用')
    } catch {
        notice.value = '日志加载失败，请检查接口或容器内日志文件权限'
        ElMessage.error('日志加载失败')
    } finally {
        loading.value = false
    }
}

/**
 * 应用文件日志结果，并把不可读等状态转成页面提示。
 */
const applyFileResult = (result: LogFileResult) => {
    const normalized = normalizeLogResult(result)

    logResult.value = normalized
    redisEntries.value = []
    redisPagination.value = {
        current_page: 1,
        per_page: perPage.value,
        total: 0,
        last_page: 1,
        has_more: false,
    }
    page.value = normalized.pagination.current_page
    perPage.value = normalized.pagination.per_page
    notice.value = normalized.message || (!normalized.exists || !normalized.readable ? '日志文件不存在或不可读' : '')

    if ((normalized.entries ?? []).length === 0 && viewMode.value === 'entries') {
        viewMode.value = 'raw'
    }
}

/**
 * 兼容新旧日志接口响应。
 *
 * Octane Worker 未重载时可能仍返回旧结构，没有 entries / facets / pagination。
 * 这里在前端补齐默认结构，避免页面直接报“日志加载失败”。
 */
const normalizeLogResult = (result: LogFileResult): LogFileResult => {
    const entries = result.entries ?? (result.lines ?? []).map((line) => ({
        time: null,
        level: 'INFO',
        summary: firstLine(line),
        content: line,
        lines: [line],
        preview_lines: [line],
        line_count: 1,
        truncated: false,
    }))
    const total = result.entry_count ?? entries.length
    const fallbackPagination = {
        current_page: page.value,
        per_page: perPage.value,
        total,
        last_page: Math.max(1, Math.ceil(total / perPage.value)),
        has_more: page.value < Math.max(1, Math.ceil(total / perPage.value)),
    }

    return {
        ...result,
        lines: result.lines ?? [],
        entries,
        facets: result.facets ?? {
            source: result.source,
            levels: [],
            timeline: [],
        },
        pagination: result.pagination ?? fallbackPagination,
        count: result.count ?? result.lines?.length ?? 0,
        entry_count: total,
    }
}

/**
 * 切换日志类型后立即刷新。
 */
const handleTabChange = async () => {
    notice.value = ''
    selectedLevel.value = ''
    page.value = 1

    if (active.value === 'system' && systemSources.value.length === 0) {
        await loadSources()
    }

    await loadLogs()
}

/**
 * 重置自动刷新定时器。
 */
const resetTimer = () => {
    if (timer) {
        window.clearInterval(timer)
        timer = null
    }

    if (autoRefresh.value) {
        timer = window.setInterval(loadLogs, 5000)
    }
}

watch(autoRefresh, resetTimer)

onMounted(async () => {
    await loadSources()
    await loadLogs()
    resetTimer()
})

onBeforeUnmount(() => {
    if (timer) {
        window.clearInterval(timer)
    }
})
</script>

<style scoped>
.logs-page {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.title {
    color: #111827;
    font-size: 18px;
    font-weight: 700;
}

.subtitle {
    color: #6b7280;
    font-size: 13px;
    margin-top: 4px;
}

.toolbar {
    align-items: flex-end;
    display: flex;
    gap: 16px;
    justify-content: space-between;
}

.tabs {
    flex: 1;
    min-width: 320px;
}

.filters {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: flex-end;
}

.keyword-input {
    width: 220px;
}

.source-select {
    width: 220px;
}

.time-range {
    width: 360px;
}

.source-state {
    float: right;
    margin-left: 12px;
}

.notice {
    margin: 12px 0;
}

.drilldown-shell {
    display: grid;
    gap: 14px;
    grid-template-columns: 280px minmax(0, 1fr);
    margin-top: 12px;
}

.drilldown-sidebar {
    align-self: start;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
    color: #111827;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 760px;
    overflow: auto;
    padding: 12px;
}

.drilldown-main {
    min-width: 0;
}

.panel-title {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.source-card,
.facet-row {
    align-items: center;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    color: #334155;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    min-height: 40px;
    padding: 8px 10px;
    text-align: left;
    transition: border-color 0.16s ease, background 0.16s ease;
    width: 100%;
}

.source-card:hover,
.facet-row:hover,
.source-card.active,
.facet-row.selected {
    background: #eff6ff;
    border-color: #3b82f6;
    color: #1d4ed8;
}

.facet-block {
    border-top: 1px solid #e5e7eb;
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 6px;
    padding-top: 12px;
}

.level-name {
    align-items: center;
    display: flex;
    gap: 8px;
}

.level-dot {
    background: #22c55e;
    border-radius: 999px;
    display: inline-block;
    height: 8px;
    width: 8px;
}

.level-dot.error,
.level-dot.critical,
.level-dot.alert,
.level-dot.emergency {
    background: #ef4444;
}

.level-dot.warning,
.level-dot.notice {
    background: #f59e0b;
}

.level-dot.debug {
    background: #60a5fa;
}

.timeline-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.timeline-row {
    align-items: center;
    color: #64748b;
    display: grid;
    font-size: 12px;
    gap: 8px;
    grid-template-columns: 38px minmax(0, 1fr) 28px;
}

.timeline-track {
    background: #e5e7eb;
    border-radius: 999px;
    height: 8px;
    overflow: hidden;
}

.timeline-bar {
    background: #3b82f6;
    border-radius: inherit;
    height: 100%;
}

.slowlog-wrap,
.log-panel {
    min-width: 0;
}

.view-mode {
    display: flex;
    justify-content: flex-end;
    margin: 12px 0;
}

.log-scroll {
    background: #111827;
    border: 1px solid #e5e7eb;
    border-top: 0;
}

.entry-list {
    background: #111827;
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-height: 620px;
    padding: 12px;
}

.log-entry {
    background: #151a21;
    border: 1px solid #2a313d;
    border-radius: 4px;
    overflow: hidden;
}

.entry-head {
    align-items: center;
    background: #1b212b;
    border-bottom: 1px solid #2a313d;
    display: flex;
    gap: 12px;
    justify-content: space-between;
    padding: 8px 10px;
}

.entry-title {
    align-items: center;
    display: flex;
    gap: 12px;
    min-width: 0;
}

.entry-time,
.entry-lines {
    color: #cbd5e1;
    font-size: 12px;
    white-space: nowrap;
}

.entry-summary-inline {
    color: #e5e7eb;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 12px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.entry-content {
    background: #151a21;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 13px;
    line-height: 1.65;
    max-height: 320px;
    overflow: auto;
    padding: 10px 12px;
}

.log-line {
    align-items: flex-start;
    color: #e5e7eb;
    display: grid;
    gap: 10px;
    grid-template-columns: 4px max-content;
    min-width: max-content;
    padding: 1px 0;
}

.line-marker {
    background: #94a3b8;
    display: inline-block;
    height: 1.45em;
    margin-top: 2px;
    width: 3px;
}

.log-line.info .line-marker {
    background: #9bc37d;
}

.log-line.error .line-marker,
.log-line.critical .line-marker,
.log-line.alert .line-marker,
.log-line.emergency .line-marker {
    background: #ef4444;
}

.log-line.warning .line-marker,
.log-line.notice .line-marker {
    background: #f59e0b;
}

.log-line.debug .line-marker {
    background: #3b82f6;
}

.log-line code {
    color: #e5e7eb;
    white-space: pre;
}

.log-meta {
    align-items: center;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-bottom: 0;
    color: #6b7280;
    display: flex;
    font-size: 12px;
    gap: 18px;
    justify-content: space-between;
    padding: 10px 12px;
}

.pagination-bar {
    align-items: center;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-top: 0;
    display: flex;
    justify-content: flex-end;
    padding: 12px;
}

.log-text {
    background: #0f172a;
    color: #d1fae5;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 12px;
    line-height: 1.7;
    margin: 0;
    min-height: 620px;
    padding: 16px;
    white-space: pre-wrap;
    word-break: break-word;
}

mark {
    background: #fde68a;
    border-radius: 3px;
    color: #111827;
    padding: 0 2px;
}

@media (max-width: 900px) {
    .card-header,
    .toolbar,
    .filters {
        align-items: stretch;
        flex-direction: column;
    }

    .drilldown-shell {
        grid-template-columns: 1fr;
    }

    .drilldown-sidebar {
        max-height: none;
    }

    .keyword-input,
    .source-select {
        width: 100%;
    }
}
</style>
