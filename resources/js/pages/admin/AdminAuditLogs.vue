<template>
    <div class="security-page">
        <el-form :inline="true" :model="filters" class="filters">
            <el-form-item label="模块"><el-input v-model="filters.module" clearable /></el-form-item>
            <el-form-item label="动作"><el-input v-model="filters.action" clearable /></el-form-item>
            <el-form-item label="结果">
                <el-select v-model="filters.result" clearable>
                    <el-option label="成功" value="success" />
                    <el-option label="失败" value="failure" />
                </el-select>
            </el-form-item>
            <el-form-item><el-button type="primary" @click="load">查询</el-button></el-form-item>
        </el-form>

        <el-table :data="logs" border>
            <el-table-column label="时间" prop="created_at" min-width="160" />
            <el-table-column label="操作者" min-width="180">
                <template #default="{ row }">{{ row.admin_name || row.admin_email || '-' }}</template>
            </el-table-column>
            <el-table-column label="模块" prop="module" min-width="150" />
            <el-table-column label="动作" prop="action" min-width="130" />
            <el-table-column label="结果" width="90">
                <template #default="{ row }">
                    <el-tag :type="row.result === 'success' ? 'success' : 'danger'">
                        {{ row.result === 'success' ? '成功' : '失败' }}
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column label="IP" prop="ip_address" min-width="130" />
            <el-table-column label="摘要" min-width="220">
                <template #default="{ row }">
                    <code>{{ JSON.stringify(row.payload) }}</code>
                </template>
            </el-table-column>
        </el-table>
    </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { getAdminAuditLogs, type AdminAuditLog } from '@/api/adminSecurity'

const logs = ref<AdminAuditLog[]>([])
const filters = reactive({
    module: '',
    action: '',
    result: '',
})

const load = async () => {
    const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
    const res = await getAdminAuditLogs(params)
    logs.value = res.data.data.items
}

onMounted(load)
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

code {
    color: #334155;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    white-space: normal;
    word-break: break-word;
}
</style>
