<template>
    <div class="security-page">
        <el-form :inline="true" :model="filters" class="filters">
            <el-form-item label="操作者ID"><el-input-number v-model="filters.admin_user_id" :min="1" controls-position="right" /></el-form-item>
            <el-form-item label="关键词"><el-input v-model="filters.keyword" clearable placeholder="邮箱/消息/模块/动作" /></el-form-item>
            <el-form-item label="模块">
                <el-select v-model="filters.module" clearable filterable allow-create default-first-option placeholder="全部模块" class="facet-select">
                    <el-option v-for="module in facets.modules" :key="module" :label="module" :value="module" />
                </el-select>
            </el-form-item>
            <el-form-item label="动作">
                <el-select v-model="filters.action" clearable filterable allow-create default-first-option placeholder="全部动作" class="facet-select">
                    <el-option v-for="action in facets.actions" :key="action" :label="action" :value="action" />
                </el-select>
            </el-form-item>
            <el-form-item label="状态码"><el-input-number v-model="filters.status_code" :min="100" :max="599" controls-position="right" /></el-form-item>
            <el-form-item label="结果">
                <el-select v-model="filters.result" clearable>
                    <el-option label="成功" value="success" />
                    <el-option label="失败" value="failure" />
                </el-select>
            </el-form-item>
            <el-form-item label="时间">
                <el-date-picker
                    v-model="filters.range"
                    type="datetimerange"
                    range-separator="至"
                    start-placeholder="开始时间"
                    end-placeholder="结束时间"
                    value-format="YYYY-MM-DD HH:mm:ss"
                    :shortcuts="dateShortcuts"
                />
            </el-form-item>
            <el-form-item label="预设">
                <el-select
                    v-model="selectedPresetId"
                    clearable
                    filterable
                    placeholder="选择预设"
                    class="facet-select"
                    @change="onPresetChange"
                >
                    <el-option v-for="preset in presets" :key="preset.id" :label="preset.name" :value="preset.id" />
                </el-select>
                <el-button :disabled="!selectedPresetId || presetBusy" text type="danger" @click="removeSelectedPreset">删除</el-button>
                <el-button :loading="presetBusy" plain @click="saveCurrentPreset">保存筛选</el-button>
            </el-form-item>
            <el-form-item>
                <el-button :loading="loading" type="primary" @click="search">查询</el-button>
                <el-button :disabled="loading" @click="reset">重置</el-button>
                <el-button :loading="exporting" :disabled="loading" @click="exportLogs">导出 CSV</el-button>
            </el-form-item>
        </el-form>

        <el-table v-loading="loading" :data="logs" border empty-text="暂无审计日志">
            <el-table-column label="时间" prop="created_at" min-width="160" />
            <el-table-column label="操作者" min-width="180">
                <template #default="{ row }">{{ row.admin_name || row.admin_email || '-' }}</template>
            </el-table-column>
            <el-table-column label="操作者ID" prop="admin_user_id" width="100" />
            <el-table-column label="模块" prop="module" min-width="150" />
            <el-table-column label="动作" prop="action" min-width="130" />
            <el-table-column label="结果" width="90">
                <template #default="{ row }">
                    <el-tag :type="row.result === 'success' ? 'success' : 'danger'">
                        {{ row.result === 'success' ? '成功' : '失败' }}
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column label="状态码" prop="status_code" width="90" />
            <el-table-column label="IP" prop="ip_address" min-width="130" />
            <el-table-column label="摘要" min-width="220">
                <template #default="{ row }">
                    <code>{{ JSON.stringify(row.payload) }}</code>
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
                @current-change="load"
                @size-change="changePageSize"
            />
        </div>
    </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
    deleteAuditPreset,
    exportAdminAuditLogs,
    getAdminAuditFacets,
    getAdminAuditLogs,
    getAuditPresets,
    saveAuditPreset,
    type AdminAuditFacets,
    type AdminAuditLog,
    type AdminAuditPreset,
} from '@/api/adminSecurity'

const logs = ref<AdminAuditLog[]>([])
const loading = ref(false)
const exporting = ref(false)
const presets = ref<AdminAuditPreset[]>([])
const selectedPresetId = ref<number | undefined>(undefined)
const presetBusy = ref(false)
const facets = reactive<AdminAuditFacets>({ modules: [], actions: [], results: [] })
const filters = reactive({
    admin_user_id: undefined as number | undefined,
    keyword: '',
    module: '',
    action: '',
    status_code: undefined as number | undefined,
    result: '',
    range: [] as string[],
})

const dateShortcuts = [
    {
        text: '今天',
        value: () => {
            const end = new Date()
            const start = new Date()
            start.setHours(0, 0, 0, 0)
            return [start, end]
        },
    },
    {
        text: '近 7 天',
        value: () => {
            const end = new Date()
            const start = new Date()
            start.setDate(start.getDate() - 7)
            return [start, end]
        },
    },
    {
        text: '近 30 天',
        value: () => {
            const end = new Date()
            const start = new Date()
            start.setDate(start.getDate() - 30)
            return [start, end]
        },
    },
]
const pagination = reactive({
    current_page: 1,
    per_page: 20,
    total: 0,
    last_page: 1,
})

const load = async () => {
    loading.value = true

    try {
        const params = auditLogParams()
        const res = await getAdminAuditLogs(params)
        logs.value = res.data.data.items
        Object.assign(pagination, res.data.data.pagination)
    } finally {
        loading.value = false
    }
}

const auditLogParams = () => {
    const params: Record<string, string | number> = {
        page: pagination.current_page,
        per_page: pagination.per_page,
    }

    if (filters.admin_user_id) params.admin_user_id = filters.admin_user_id
    if (filters.keyword) params.keyword = filters.keyword
    if (filters.module) params.module = filters.module
    if (filters.action) params.action = filters.action
    if (filters.status_code) params.status_code = filters.status_code
    if (filters.result) params.result = filters.result
    if (filters.range?.[0]) params.from = filters.range[0]
    if (filters.range?.[1]) params.to = filters.range[1]

    return params
}

const search = async () => {
    pagination.current_page = 1
    await load()
}

const reset = async () => {
    Object.assign(filters, {
        admin_user_id: undefined,
        keyword: '',
        module: '',
        action: '',
        status_code: undefined,
        result: '',
        range: [],
    })
    pagination.current_page = 1
    await load()
}

const loadFacets = async () => {
    try {
        const res = await getAdminAuditFacets()
        Object.assign(facets, res.data.data)
    } catch {
        // facets 仅用于下拉建议，失败不阻塞列表加载
    }
}

const loadPresets = async () => {
    try {
        const res = await getAuditPresets()
        presets.value = res.data.data.items
    } catch {
        // 预设失败不阻塞列表加载
    }
}

const applyPreset = (preset: AdminAuditPreset) => {
    const f = preset.filters
    Object.assign(filters, {
        admin_user_id: (f.admin_user_id as number | undefined) ?? undefined,
        keyword: (f.keyword as string) ?? '',
        module: (f.module as string) ?? '',
        action: (f.action as string) ?? '',
        status_code: (f.status_code as number | undefined) ?? undefined,
        result: (f.result as string) ?? '',
        range: f.from || f.to ? [String(f.from ?? ''), String(f.to ?? '')] : [],
    })
}

const onPresetChange = async (id: number | undefined) => {
    if (!id) return
    const preset = presets.value.find(p => p.id === id)
    if (!preset) return
    applyPreset(preset)
    await search()
}

const saveCurrentPreset = async () => {
    let name = ''

    try {
        const { value } = await ElMessageBox.prompt('输入预设名称（同名将覆盖）', '保存筛选预设', {
            confirmButtonText: '保存',
            cancelButtonText: '取消',
            inputValidator: v => (v && v.trim() !== '' ? true : '请输入名称'),
        })
        name = value.trim()
    } catch {
        return
    }

    const params = auditLogParams()
    delete params.page
    delete params.per_page

    presetBusy.value = true

    try {
        await saveAuditPreset({ name, filters: params })
        ElMessage.success('已保存预设')
        await loadPresets()
    } catch {
        ElMessage.error('保存预设失败')
    } finally {
        presetBusy.value = false
    }
}

const removeSelectedPreset = async () => {
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

    presetBusy.value = true

    try {
        await deleteAuditPreset(selectedPresetId.value)
        ElMessage.success('已删除')
        selectedPresetId.value = undefined
        await loadPresets()
    } catch {
        ElMessage.error('删除预设失败')
    } finally {
        presetBusy.value = false
    }
}

const changePageSize = async (size: number) => {
    pagination.per_page = size
    pagination.current_page = 1
    await load()
}

const saveBlob = (blob: Blob, filename: string) => {
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
}

const exportLogs = async () => {
    exporting.value = true

    try {
        const params = auditLogParams()
        delete params.page
        delete params.per_page

        const res = await exportAdminAuditLogs(params)
        saveBlob(res.data, 'admin-audit-logs.csv')
        ElMessage.success('审计日志导出已开始')
    } finally {
        exporting.value = false
    }
}

onMounted(async () => {
    await Promise.all([load(), loadFacets(), loadPresets()])
})
</script>

<style scoped>
.security-page {
    display: grid;
    gap: 16px;
}

.filters {
    background: #fff;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    padding: 16px 16px 0;
}

.facet-select {
    width: 180px;
}

code {
    color: #334155;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    white-space: normal;
    word-break: break-word;
}

.pagination {
    display: flex;
    justify-content: flex-end;
}
</style>
