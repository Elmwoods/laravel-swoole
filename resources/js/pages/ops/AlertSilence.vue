<template>
    <div class="alert-silence">
        <el-alert
            v-if="overview.active.length"
            type="warning"
            show-icon
            :closable="false"
            :title="`${overview.active.length} 条静默生效中：命中的告警暂不外发到通道（仍入库、可在告警中心查看）。`"
        />

        <div class="toolbar">
            <el-button v-if="canManage" type="primary" @click="openCreate">新增静默</el-button>
            <el-button :loading="loading" @click="load">刷新</el-button>
        </div>

        <el-table v-loading="loading" :data="overview.items" border empty-text="暂无静默窗口">
            <el-table-column label="备注" prop="label">
                <template #default="{ row }">{{ row.label || '—' }}</template>
            </el-table-column>
            <el-table-column label="时间段" width="330">
                <template #default="{ row }">{{ windowText(row) }}</template>
            </el-table-column>
            <el-table-column label="来源">
                <template #default="{ row }">
                    <template v-if="row.sources.length">
                        <el-tag v-for="s in row.sources" :key="s" size="small" effect="plain" class="cell-tag">{{ s }}</el-tag>
                    </template>
                    <span v-else class="muted">全部</span>
                </template>
            </el-table-column>
            <el-table-column label="严重级" width="150">
                <template #default="{ row }">
                    <template v-if="row.severities.length">
                        <el-tag v-for="s in row.severities" :key="s" size="small" :type="sevType(s)" class="cell-tag">{{ sevLabel(s) }}</el-tag>
                    </template>
                    <span v-else class="muted">全部</span>
                </template>
            </el-table-column>
            <el-table-column label="生效" width="90">
                <template #default="{ row }">
                    <el-switch
                        :model-value="row.is_active"
                        :disabled="!canManage"
                        @change="(v: boolean) => toggle(row, v)"
                    />
                </template>
            </el-table-column>
            <el-table-column label="操作" width="90">
                <template #default="{ row }">
                    <el-button v-if="canManage" link type="danger" @click="remove(row)">删除</el-button>
                </template>
            </el-table-column>
        </el-table>

        <el-dialog v-model="dialogVisible" title="新增静默窗口" width="560px">
            <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
                <el-form-item label="备注" prop="label">
                    <el-input v-model="form.label" placeholder="可选，如 发布窗口 / 机房维护" />
                </el-form-item>
                <el-form-item label="重复方式">
                    <el-radio-group v-model="form.recurrence">
                        <el-radio-button value="once">一次性</el-radio-button>
                        <el-radio-button value="daily">每天</el-radio-button>
                        <el-radio-button value="weekly">每周</el-radio-button>
                    </el-radio-group>
                </el-form-item>
                <el-form-item :label="form.recurrence === 'once' ? '时间段' : '生效范围'" prop="range">
                    <el-date-picker
                        v-model="form.range"
                        type="datetimerange"
                        value-format="YYYY-MM-DD HH:mm:ss"
                        start-placeholder="开始时间"
                        end-placeholder="结束时间"
                        style="width: 100%"
                    />
                </el-form-item>
                <template v-if="form.recurrence !== 'once'">
                    <el-form-item label="每天时段" prop="times">
                        <el-time-picker v-model="form.startTime" value-format="HH:mm" format="HH:mm" placeholder="开始时刻" />
                        <span class="sep">至</span>
                        <el-time-picker v-model="form.endTime" value-format="HH:mm" format="HH:mm" placeholder="结束时刻" />
                    </el-form-item>
                    <el-form-item v-if="form.recurrence === 'weekly'" label="星期" prop="daysOfWeek">
                        <el-select v-model="form.daysOfWeek" multiple placeholder="选择星期" style="width: 100%">
                            <el-option v-for="d in WEEKDAYS" :key="d.value" :label="d.label" :value="d.value" />
                        </el-select>
                    </el-form-item>
                </template>
                <el-form-item label="来源（留空=全部来源）" prop="sources">
                    <el-select v-model="form.sources" multiple filterable allow-create default-first-option placeholder="选择或输入来源" style="width: 100%">
                        <el-option v-for="s in SOURCE_OPTIONS" :key="s" :label="s" :value="s" />
                    </el-select>
                </el-form-item>
                <el-form-item label="严重级（留空=全部严重级）" prop="severities">
                    <el-select v-model="form.severities" multiple placeholder="选择严重级" style="width: 100%">
                        <el-option label="严重" value="critical" />
                        <el-option label="警告" value="warning" />
                        <el-option label="提示" value="info" />
                    </el-select>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button :disabled="saving" @click="dialogVisible = false">取消</el-button>
                <el-button :loading="saving" type="primary" @click="submit">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
    createAlertSilence,
    deleteAlertSilence,
    getAlertSilences,
    toggleAlertSilence,
    type AlertSilence,
    type AlertSilenceOverview,
    type SilenceRecurrence,
} from '@/api/opsAlertSilence'
import { useAdminAuthStore } from '@/stores/adminAuth'

const SOURCE_OPTIONS = [
    'disk', 'queue', 'docker', 'network', 'system', 'redis', 'mysql', 'octane', 'supervisor',
    'inspection', 'security_login', 'security_audit', 'security_access', 'channel_health',
]

// Carbon dayOfWeek：0=周日…6=周六。
const WEEKDAYS = [
    { label: '周日', value: 0 },
    { label: '周一', value: 1 },
    { label: '周二', value: 2 },
    { label: '周三', value: 3 },
    { label: '周四', value: 4 },
    { label: '周五', value: 5 },
    { label: '周六', value: 6 },
]
const dayLabel = (n: number) => WEEKDAYS.find(d => d.value === n)?.label ?? String(n)

const auth = useAdminAuthStore()
const canManage = computed(() => auth.hasPermission('ops.alerts.manage'))

const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const overview = ref<AlertSilenceOverview>({ items: [], active: [] })

const formRef = ref<FormInstance>()
const form = reactive<{
    label: string
    range: string[]
    recurrence: SilenceRecurrence
    startTime: string
    endTime: string
    daysOfWeek: number[]
    sources: string[]
    severities: string[]
}>({
    label: '',
    range: [],
    recurrence: 'once',
    startTime: '',
    endTime: '',
    daysOfWeek: [],
    sources: [],
    severities: [],
})

const rules: FormRules = {
    range: [{ required: true, message: '请选择时间段', trigger: 'change', type: 'array', len: 2 }],
    times: [{
        validator: (_r, _v, cb) => {
            if (form.recurrence !== 'once' && (!form.startTime || !form.endTime)) cb(new Error('请选择每天时段'))
            else cb()
        },
        trigger: 'change',
    }],
    daysOfWeek: [{
        validator: (_r, _v, cb) => {
            if (form.recurrence === 'weekly' && form.daysOfWeek.length === 0) cb(new Error('请选择至少一天'))
            else cb()
        },
        trigger: 'change',
    }],
}

const sevLabel = (s: string) => (s === 'critical' ? '严重' : s === 'warning' ? '警告' : '提示')
const sevType = (s: string) => (s === 'critical' ? 'danger' : s === 'warning' ? 'warning' : 'info')

const windowText = (row: AlertSilence): string => {
    if (row.recurrence === 'daily') return `每天 ${row.start_time}–${row.end_time}`
    if (row.recurrence === 'weekly') return `每周${(row.days_of_week || []).map(dayLabel).join('/')} ${row.start_time}–${row.end_time}`
    return `${row.starts_at} ~ ${row.ends_at}`
}

const load = async () => {
    loading.value = true
    try {
        const res = await getAlertSilences()
        overview.value = res.data.data
    } finally {
        loading.value = false
    }
}

const openCreate = () => {
    Object.assign(form, { label: '', range: [], recurrence: 'once', startTime: '', endTime: '', daysOfWeek: [], sources: [], severities: [] })
    formRef.value?.clearValidate()
    dialogVisible.value = true
}

const submit = async () => {
    if (saving.value) return
    const valid = await formRef.value?.validate().catch(() => false)
    if (!valid) return

    saving.value = true
    try {
        await createAlertSilence({
            label: form.label.trim() || null,
            starts_at: form.range[0],
            ends_at: form.range[1],
            recurrence: form.recurrence,
            start_time: form.recurrence === 'once' ? null : form.startTime,
            end_time: form.recurrence === 'once' ? null : form.endTime,
            days_of_week: form.recurrence === 'weekly' ? form.daysOfWeek : [],
            sources: form.sources,
            severities: form.severities,
        })
        ElMessage.success('已创建静默')
        dialogVisible.value = false
        await load()
    } finally {
        saving.value = false
    }
}

const toggle = async (row: AlertSilence, active: boolean) => {
    await toggleAlertSilence(row.id, active)
    row.is_active = active
    await load()
}

const remove = async (row: AlertSilence) => {
    try {
        await ElMessageBox.confirm('确认删除该静默窗口？', '删除静默', {
            type: 'warning',
            confirmButtonText: '删除',
            cancelButtonText: '取消',
        })
    } catch {
        return
    }
    await deleteAlertSilence(row.id)
    ElMessage.success('已删除')
    await load()
}

onMounted(load)
</script>

<style scoped>
.alert-silence {
    display: grid;
    gap: 16px;
}

.toolbar {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.cell-tag {
    margin: 2px 4px 2px 0;
}

.muted {
    color: #94a3b8;
}

.sep {
    margin: 0 8px;
    color: #64748b;
}
</style>
