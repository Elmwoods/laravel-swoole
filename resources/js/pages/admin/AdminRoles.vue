<template>
    <div class="security-page">
        <div class="toolbar">
            <el-button type="primary" @click="openCreate">新增角色</el-button>
        </div>

        <el-table v-loading="loading" :data="roles" border empty-text="暂无角色">
            <el-table-column label="角色" prop="name" />
            <el-table-column label="标识" prop="slug" />
            <el-table-column label="权限数" width="100">
                <template #default="{ row }">{{ row.permissions.length }}</template>
            </el-table-column>
            <el-table-column label="状态" width="100">
                <template #default="{ row }">
                    <el-tag :type="row.is_active ? 'success' : 'danger'">{{ row.is_active ? '启用' : '禁用' }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="系统角色" width="110">
                <template #default="{ row }">
                    <el-tag :type="row.is_system ? 'warning' : 'info'" effect="plain">{{ row.is_system ? '是' : '否' }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="操作" width="120">
                <template #default="{ row }">
                    <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                </template>
            </el-table-column>
        </el-table>

        <el-dialog v-model="dialogVisible" :title="editing ? '编辑角色' : '新增角色'" width="680px">
            <el-form :model="form" label-position="top">
                <el-form-item label="角色名称"><el-input v-model="form.name" /></el-form-item>
                <el-form-item label="角色标识"><el-input v-model="form.slug" :disabled="editing?.is_system" /></el-form-item>
                <el-form-item label="描述"><el-input v-model="form.description" /></el-form-item>
                <el-alert
                    v-if="isEditingSuperAdmin"
                    class="system-alert"
                    type="warning"
                    show-icon
                    :closable="false"
                    title="超级管理员角色必须保持启用并拥有全部权限"
                />
                <el-form-item label="状态"><el-switch v-model="form.is_active" :disabled="isEditingSuperAdmin" /></el-form-item>
                <el-form-item label="权限">
                    <el-checkbox-group v-model="form.permission_ids" class="permission-grid">
                        <el-checkbox
                            v-for="permission in permissions"
                            :key="permission.id"
                            :label="permission.id"
                            :disabled="isEditingSuperAdmin"
                        >
                            <span>{{ permission.name }}</span>
                            <small>{{ permission.slug }}</small>
                        </el-checkbox>
                    </el-checkbox-group>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button :disabled="saving" @click="dialogVisible = false">取消</el-button>
                <el-button :loading="saving" type="primary" @click="save">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import {
    createAdminRole,
    getAdminRoles,
    updateAdminRole,
    type AdminPermission,
    type AdminRole,
} from '@/api/adminSecurity'

const roles = ref<AdminRole[]>([])
const permissions = ref<AdminPermission[]>([])
const dialogVisible = ref(false)
const loading = ref(false)
const saving = ref(false)
const editing = ref<AdminRole | null>(null)
const form = reactive<any>({
    name: '',
    slug: '',
    description: '',
    is_active: true,
    permission_ids: [],
})

const isEditingSuperAdmin = computed(() => editing.value?.slug === 'super_admin')

const load = async () => {
    loading.value = true

    try {
        const res = await getAdminRoles()
        roles.value = res.data.data.roles
        permissions.value = res.data.data.permissions
    } finally {
        loading.value = false
    }
}

const openCreate = () => {
    editing.value = null
    Object.assign(form, { name: '', slug: '', description: '', is_active: true, permission_ids: [] })
    dialogVisible.value = true
}

const openEdit = (row: AdminRole) => {
    editing.value = row
    Object.assign(form, {
        name: row.name,
        slug: row.slug,
        description: row.description || '',
        is_active: row.is_active,
        permission_ids: row.permissions.map(permission => permission.id),
    })
    dialogVisible.value = true
}

const save = async () => {
    if (saving.value) return

    saving.value = true

    try {
        if (isEditingSuperAdmin.value) {
            form.is_active = true
            form.permission_ids = permissions.value.map(permission => permission.id)
        }

        if (editing.value) {
            await updateAdminRole(editing.value.id, form)
        } else {
            await createAdminRole(form)
        }

        ElMessage.success('已保存')
        dialogVisible.value = false
        await load()
    } finally {
        saving.value = false
    }
}

watch(isEditingSuperAdmin, value => {
    if (!value) return

    form.is_active = true
    form.permission_ids = permissions.value.map(permission => permission.id)
})

onMounted(load)
</script>

<style scoped>
.security-page {
    display: grid;
    gap: 16px;
}

.toolbar {
    display: flex;
    justify-content: flex-end;
}

.system-alert {
    margin-bottom: 14px;
}

.permission-grid {
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    width: 100%;
}

.permission-grid :deep(.el-checkbox) {
    align-items: flex-start;
    border: 1px solid #dbe3ef;
    border-radius: 6px;
    height: auto;
    margin-right: 0;
    padding: 10px;
    white-space: normal;
}

small {
    color: #64748b;
    display: block;
    margin-top: 3px;
}
</style>
