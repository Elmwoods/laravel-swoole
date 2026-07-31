<template>
    <!-- 后台审计日志页：顶部多维筛选表单 + 日志表格 + 分页 -->
    <div class="security-page">
        <!-- 筛选表单：按操作者/关键词/模块/动作/状态码/结果/时间范围过滤，并支持筛选预设的存取 -->
        <el-form :inline="true" :model="filters" class="filters">
            <el-form-item label="操作者ID"><el-input-number v-model="filters.admin_user_id" :min="1" controls-position="right" /></el-form-item>
            <el-form-item label="关键词"><el-input v-model="filters.keyword" clearable placeholder="邮箱/消息/模块/动作" /></el-form-item>
            <!-- 模块下拉：选项来自后端 facets 聚合，allow-create 允许输入 facets 之外的自定义值 -->
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
                <el-select v-model="filters.result" clearable placeholder="全部结果" class="facet-select">
                    <el-option label="成功" value="success" />
                    <el-option label="失败" value="failure" />
                </el-select>
            </el-form-item>
            <!-- 时间范围选择：带今天/近7天/近30天快捷项，value-format 直接产出后端所需字符串 -->
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
            <!-- 筛选预设：选择后套用并查询，可删除选中预设或把当前筛选另存为预设 -->
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
            <!-- 操作区：查询 / 重置 / 导出 CSV（导出与查询共用筛选条件） -->
            <el-form-item>
                <el-button :loading="loading" type="primary" @click="search">查询</el-button>
                <el-button :disabled="loading" @click="reset">重置</el-button>
                <el-button :loading="exporting" :disabled="loading" @click="exportLogs">导出 CSV</el-button>
            </el-form-item>
        </el-form>

        <!-- 审计日志表格 -->
        <el-table v-loading="loading" :data="logs" border empty-text="暂无审计日志">
            <el-table-column label="时间" prop="created_at" min-width="160" />
            <!-- 操作者列：优先显示姓名，其次邮箱，都缺失时占位 -->
            <el-table-column label="操作者" min-width="180">
                <template #default="{ row }">{{ row.admin_name || row.admin_email || '-' }}</template>
            </el-table-column>
            <el-table-column label="操作者ID" prop="admin_user_id" width="100" />
            <el-table-column label="模块" prop="module" min-width="150" />
            <el-table-column label="动作" prop="action" min-width="130" />
            <!-- 结果列：成功绿标签 / 失败红标签，便于快速定位失败操作 -->
            <el-table-column label="结果" width="90">
                <template #default="{ row }">
                    <el-tag :type="row.result === 'success' ? 'success' : 'danger'">
                        {{ row.result === 'success' ? '成功' : '失败' }}
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column label="状态码" prop="status_code" width="90" />
            <el-table-column label="IP" prop="ip_address" min-width="130" />
            <!-- 摘要列：把结构化的 payload 序列化为 JSON 字符串原样展示，便于排查细节 -->
            <el-table-column label="摘要" min-width="220">
                <template #default="{ row }">
                    <code>{{ JSON.stringify(row.payload) }}</code>
                </template>
            </el-table-column>
        </el-table>

        <!-- 分页：翻页触发 load，改每页条数触发 changePageSize（会重置到第一页） -->
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

// 当前页审计日志数据
const logs = ref<AdminAuditLog[]>([])
// 列表查询进行中标志
const loading = ref(false)
// CSV 导出进行中标志（与列表 loading 分开，导出时不遮挡表格）
const exporting = ref(false)
// 已保存的筛选预设列表
const presets = ref<AdminAuditPreset[]>([])
// 当前选中的预设 id，undefined 表示未选
const selectedPresetId = ref<number | undefined>(undefined)
// 预设的保存/删除操作进行中标志，用于禁用相关按钮防重复提交
const presetBusy = ref(false)
// 筛选项的可选值聚合（模块/动作/结果），用于下拉建议，来源于后端 facets 接口
const facets = reactive<AdminAuditFacets>({ modules: [], actions: [], results: [] })
// 筛选条件的响应式模型；range 为 [开始, 结束] 时间字符串数组
const filters = reactive({
    admin_user_id: undefined as number | undefined,
    keyword: '',
    module: '',
    action: '',
    status_code: undefined as number | undefined,
    result: '',
    range: [] as string[],
})

// 日期选择器的快捷区间：预生成常用时间范围，减少用户手动选日期的操作
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
// 分页状态：当前页/每页条数/总数/总页数，后端返回后整体回填
const pagination = reactive({
    current_page: 1,
    per_page: 20,
    total: 0,
    last_page: 1,
})

// 按当前筛选+分页拉取日志。
// 为什么：翻页、改页大小、查询、重置、套用预设都最终调用它，作为唯一的取数入口。
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

// 组装请求参数。
// 为什么：只把有值的筛选项塞进 params，避免向后端传空字符串/undefined 造成无效过滤；
// 也被导出和保存预设复用（它们会再删掉分页参数）。
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

// 查询：点击「查询」时回到第一页再取数，避免停留在越界页码
const search = async () => {
    pagination.current_page = 1
    await load()
}

// 重置：清空所有筛选项并回到第一页重新加载
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

// 加载筛选项聚合值（模块/动作/结果的可选项）
const loadFacets = async () => {
    try {
        const res = await getAdminAuditFacets()
        Object.assign(facets, res.data.data)
    } catch {
        // facets 仅用于下拉建议，失败不阻塞列表加载
    }
}

// 加载已保存的筛选预设
const loadPresets = async () => {
    try {
        const res = await getAuditPresets()
        presets.value = res.data.data.items
    } catch {
        // 预设失败不阻塞列表加载
    }
}

// 把预设中的筛选条件回填到 filters。
// 为什么：预设里的字段可能缺失，逐项用 ?? 兜底默认值，防止 undefined 破坏受控输入；
// range 特殊处理，只有存在 from/to 时才组装成数组。
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

// 下拉选中预设时：套用其筛选条件并立即查询，让用户所选即所得
const onPresetChange = async (id: number | undefined) => {
    if (!id) return
    const preset = presets.value.find(p => p.id === id)
    if (!preset) return
    applyPreset(preset)
    await search()
}

// 把当前筛选条件保存为命名预设。
// 为什么：先让用户输入名称（同名覆盖），再剔除分页参数后保存，预设只关心筛选条件本身。
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
    // 预设只保存筛选条件，分页无意义，删除 page/per_page
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

// 删除当前选中的预设。
// 为什么：删除不可恢复，先弹确认框；成功后清空选中态并刷新预设列表。
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

// 修改每页条数：改变 per_page 后回到第一页重新加载，避免页码越界
const changePageSize = async (size: number) => {
    pagination.per_page = size
    pagination.current_page = 1
    await load()
}

// 触发浏览器下载 Blob。
// 为什么：把后端返回的 CSV Blob 转成临时对象 URL，通过隐藏 a 标签触发下载后立即回收 URL。
const saveBlob = (blob: Blob, filename: string) => {
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
}

// 导出当前筛选条件下的全部日志为 CSV。
// 为什么：导出的是符合筛选的全量数据而非当前页，因此去掉分页参数。
const exportLogs = async () => {
    exporting.value = true

    try {
        const params = auditLogParams()
        // 导出全量匹配数据，不受分页限制
        delete params.page
        delete params.per_page

        const res = await exportAdminAuditLogs(params)
        saveBlob(res.data, 'admin-audit-logs.csv')
        ElMessage.success('审计日志导出已开始')
    } finally {
        exporting.value = false
    }
}

// 首次挂载：并发拉取首页日志、facets 下拉建议、筛选预设，三者互不依赖
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
