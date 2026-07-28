<template>
    <div class="on-call">
        <el-alert
            v-if="current"
            type="success"
            show-icon
            :closable="false"
            :title="`当前值班：${current}（开启值班自动指派后，新告警会自动指派给当前值班人）`"
        />
        <el-alert
            v-else
            type="info"
            show-icon
            :closable="false"
            title="当前无人值班（无生效中的班次）。"
        />

        <div class="toolbar">
            <el-button v-if="canManage" type="primary" @click="openCreate">新增班次</el-button>
            <el-button :loading="loading" @click="load">刷新</el-button>
        </div>

        <el-table v-loading="loading" :data="items" border empty-text="暂无值班班次">
            <el-table-column label="值班人" prop="assignee" min-width="140">
                <template #default="{ row }">
                    {{ row.assignee }}
                    <el-tag v-if="row.current" type="success" size="small" effect="dark" class="cell-tag">值班中</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="备注" prop="label">
                <template #default="{ row }">{{ row.label || '—' }}</template>
            </el-table-column>
            <el-table-column label="时间段" width="360">
                <template #default="{ row }">{{ windowText(row) }}</template>
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

        <el-dialog v-model="dialogVisible" title="新增值班班次" width="520px">
            <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
                <el-form-item label="值班人" prop="assignee">
                    <el-input v-model="form.assignee" placeholder="如 张三 / oncall@team" maxlength="120" />
                </el-form-item>
                <el-form-item label="备注" prop="label">
                    <el-input v-model="form.label" placeholder="可选，如 夜班 / 周末班" maxlength="120" />
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
    createOnCallShift,
    deleteOnCallShift,
    getOnCall,
    toggleOnCallShift,
    type OnCallRecurrence,
    type OnCallShift,
} from '@/api/opsStage4'
import { useAdminAuthStore } from '@/stores/adminAuth'

const auth = useAdminAuthStore()
const canManage = computed(() => auth.hasPermission('ops.alerts.manage'))

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

const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const items = ref<OnCallShift[]>([])
const current = ref<string | null>(null)

const formRef = ref<FormInstance>()
const form = reactive<{
    assignee: string
    label: string
    range: string[]
    recurrence: OnCallRecurrence
    startTime: string
    endTime: string
    daysOfWeek: number[]
}>({
    assignee: '',
    label: '',
    range: [],
    recurrence: 'once',
    startTime: '',
    endTime: '',
    daysOfWeek: [],
})

const rules: FormRules = {
    assignee: [{ required: true, message: '请填写值班人', trigger: 'blur' }],
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

const windowText = (row: OnCallShift): string => {
    if (row.recurrence === 'daily') return `每天 ${row.start_time}–${row.end_time}`
    if (row.recurrence === 'weekly') return `每周${(row.days_of_week || []).map(dayLabel).join('/')} ${row.start_time}–${row.end_time}`
    return `${row.starts_at} ~ ${row.ends_at}`
}

const load = async () => {
    loading.value = true
    try {
        const res = await getOnCall()
        items.value = res.data.data.items
        current.value = res.data.data.current
    } finally {
        loading.value = false
    }
}

const openCreate = () => {
    Object.assign(form, { assignee: '', label: '', range: [], recurrence: 'once', startTime: '', endTime: '', daysOfWeek: [] })
    formRef.value?.clearValidate()
    dialogVisible.value = true
}

const submit = async () => {
    if (saving.value) return
    const valid = await formRef.value?.validate().catch(() => false)
    if (!valid) return

    saving.value = true
    try {
        await createOnCallShift({
            assignee: form.assignee.trim(),
            label: form.label.trim() || null,
            starts_at: form.range[0],
            ends_at: form.range[1],
            recurrence: form.recurrence,
            start_time: form.recurrence === 'once' ? null : form.startTime,
            end_time: form.recurrence === 'once' ? null : form.endTime,
            days_of_week: form.recurrence === 'weekly' ? form.daysOfWeek : [],
        })
        ElMessage.success('已创建班次')
        dialogVisible.value = false
        await load()
    } finally {
        saving.value = false
    }
}

const toggle = async (row: OnCallShift, active: boolean) => {
    await toggleOnCallShift(row.id, active)
    row.is_active = active
    await load()
}

const remove = async (row: OnCallShift) => {
    try {
        await ElMessageBox.confirm('确认删除该值班班次？', '删除班次', {
            type: 'warning',
            confirmButtonText: '删除',
            cancelButtonText: '取消',
        })
    } catch {
        return
    }
    await deleteOnCallShift(row.id)
    ElMessage.success('已删除')
    await load()
}

onMounted(load)
</script>

<style scoped>
.on-call {
    display: grid;
    gap: 16px;
}

.toolbar {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.cell-tag {
    margin-left: 6px;
}

.sep {
    margin: 0 8px;
    color: #64748b;
}
</style>
