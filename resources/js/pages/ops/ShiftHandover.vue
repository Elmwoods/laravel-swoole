<template>
    <div class="shift-handover">
        <div class="toolbar">
            <el-button v-if="canManage" type="primary" @click="openCreate">记录交接</el-button>
            <el-button :loading="loading" @click="load">刷新</el-button>
        </div>

        <el-table v-loading="loading" :data="items" border empty-text="暂无交接记录">
            <el-table-column label="交出" min-width="140">
                <template #default="{ row }">{{ row.from_assignee || '—' }}</template>
            </el-table-column>
            <el-table-column label="接手" min-width="140" prop="to_assignee" />
            <el-table-column label="备注" min-width="240">
                <template #default="{ row }">{{ row.note || '—' }}</template>
            </el-table-column>
            <el-table-column label="当时 open 告警" width="150" prop="open_alert_count" />
            <el-table-column label="时间" width="180" prop="created_at" />
        </el-table>

        <el-dialog v-model="dialogVisible" title="记录值班交接" width="520px">
            <el-form :model="form" label-position="top">
                <el-form-item label="交出人（留空=上一条交接的接手人）">
                    <el-input v-model="form.from_assignee" placeholder="可选" maxlength="120" />
                </el-form-item>
                <el-form-item label="接手人（留空=当前值班人）">
                    <el-input v-model="form.to_assignee" placeholder="可选，留空自动取当前值班" maxlength="120" />
                </el-form-item>
                <el-form-item label="交接备注">
                    <el-input v-model="form.note" type="textarea" :rows="3" maxlength="2000" placeholder="未完事项 / 注意点" />
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
import { ElMessage } from 'element-plus'
import { createHandover, getHandovers, type ShiftHandover } from '@/api/opsStage4'
import { useAdminAuthStore } from '@/stores/adminAuth'

const auth = useAdminAuthStore()
const canManage = computed(() => auth.hasPermission('ops.alerts.manage'))

const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const items = ref<ShiftHandover[]>([])
const form = reactive<{ from_assignee: string; to_assignee: string; note: string }>({
    from_assignee: '',
    to_assignee: '',
    note: '',
})

const load = async () => {
    loading.value = true
    try {
        const res = await getHandovers()
        items.value = res.data.data.items
    } finally {
        loading.value = false
    }
}

const openCreate = () => {
    Object.assign(form, { from_assignee: '', to_assignee: '', note: '' })
    dialogVisible.value = true
}

const submit = async () => {
    if (saving.value) return
    saving.value = true
    try {
        await createHandover({
            from_assignee: form.from_assignee.trim() || null,
            to_assignee: form.to_assignee.trim() || null,
            note: form.note.trim() || null,
        })
        ElMessage.success('已记录交接')
        dialogVisible.value = false
        await load()
    } catch {
        ElMessage.error('记录失败：请确认接手人（当前无值班时必填）')
    } finally {
        saving.value = false
    }
}

onMounted(load)
</script>

<style scoped>
.shift-handover {
    display: grid;
    gap: 16px;
}

.toolbar {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}
</style>
