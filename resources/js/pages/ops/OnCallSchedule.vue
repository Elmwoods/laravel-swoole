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
                <template #default="{ row }">{{ row.starts_at }} ~ {{ row.ends_at }}</template>
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
                <el-form-item label="时间段" prop="range">
                    <el-date-picker
                        v-model="form.range"
                        type="datetimerange"
                        value-format="YYYY-MM-DD HH:mm:ss"
                        start-placeholder="开始时间"
                        end-placeholder="结束时间"
                        style="width: 100%"
                    />
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
    createOnCallShift,
    deleteOnCallShift,
    getOnCall,
    toggleOnCallShift,
    type OnCallShift,
} from '@/api/opsStage4'
import { useAdminAuthStore } from '@/stores/adminAuth'

const auth = useAdminAuthStore()
const canManage = computed(() => auth.hasPermission('ops.alerts.manage'))

const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const items = ref<OnCallShift[]>([])
const current = ref<string | null>(null)

const formRef = ref<FormInstance>()
const form = reactive<{ assignee: string; label: string; range: string[] }>({
    assignee: '',
    label: '',
    range: [],
})

const rules: FormRules = {
    assignee: [{ required: true, message: '请填写值班人', trigger: 'blur' }],
    range: [{ required: true, message: '请选择时间段', trigger: 'change', type: 'array', len: 2 }],
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
    Object.assign(form, { assignee: '', label: '', range: [] })
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
</style>
