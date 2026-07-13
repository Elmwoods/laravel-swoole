<template>
    <div class="security-page">
        <div class="toolbar">
            <el-button type="primary" @click="openCreate">新增管理员</el-button>
        </div>

        <el-table :data="users" border>
            <el-table-column label="姓名" prop="name" />
            <el-table-column label="邮箱" prop="email" min-width="180" />
            <el-table-column label="角色" min-width="180">
                <template #default="{ row }">
                    <el-space wrap>
                        <el-tag v-for="role in row.roles" :key="role.id" effect="plain">{{ role.name }}</el-tag>
                    </el-space>
                </template>
            </el-table-column>
            <el-table-column label="状态" width="100">
                <template #default="{ row }">
                    <el-tag :type="row.is_active ? 'success' : 'danger'">{{ row.is_active ? '启用' : '禁用' }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="操作" width="210">
                <template #default="{ row }">
                    <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                    <el-button link type="warning" @click="openPassword(row)">重置密码</el-button>
                </template>
            </el-table-column>
        </el-table>

        <el-dialog v-model="dialogVisible" :title="editing ? '编辑管理员' : '新增管理员'" width="520px">
            <el-form :model="form" label-position="top">
                <el-form-item label="姓名"><el-input v-model="form.name" /></el-form-item>
                <el-form-item label="邮箱"><el-input v-model="form.email" /></el-form-item>
                <el-form-item v-if="!editing" label="密码"><el-input v-model="form.password" show-password type="password" /></el-form-item>
                <el-form-item label="状态"><el-switch v-model="form.is_active" /></el-form-item>
                <el-form-item label="角色">
                    <el-select v-model="form.role_ids" multiple>
                        <el-option v-for="role in roles" :key="role.id" :label="role.name" :value="role.id" />
                    </el-select>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialogVisible = false">取消</el-button>
                <el-button type="primary" @click="save">保存</el-button>
            </template>
        </el-dialog>

        <el-dialog v-model="passwordVisible" title="重置密码" width="420px">
            <el-form :model="passwordForm" label-position="top">
                <el-form-item label="新密码"><el-input v-model="passwordForm.password" show-password type="password" /></el-form-item>
                <el-form-item label="确认密码"><el-input v-model="passwordForm.password_confirmation" show-password type="password" /></el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="passwordVisible = false">取消</el-button>
                <el-button type="primary" @click="savePassword">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
    createAdminUser,
    getAdminRoles,
    getAdminUsers,
    resetAdminPassword,
    updateAdminUser,
    type AdminRole,
    type AdminUser,
} from '@/api/adminSecurity'

const users = ref<AdminUser[]>([])
const roles = ref<AdminRole[]>([])
const dialogVisible = ref(false)
const passwordVisible = ref(false)
const editing = ref<AdminUser | null>(null)
const passwordTarget = ref<AdminUser | null>(null)
const form = reactive<any>({ name: '', email: '', password: '', is_active: true, role_ids: [] })
const passwordForm = reactive({ password: '', password_confirmation: '' })

const load = async () => {
    const [userRes, roleRes] = await Promise.all([getAdminUsers(), getAdminRoles()])
    users.value = userRes.data.data.items
    roles.value = roleRes.data.data.roles
}

const openCreate = () => {
    editing.value = null
    Object.assign(form, { name: '', email: '', password: '', is_active: true, role_ids: [] })
    dialogVisible.value = true
}

const openEdit = (row: AdminUser) => {
    editing.value = row
    Object.assign(form, {
        name: row.name,
        email: row.email,
        password: '',
        is_active: row.is_active,
        role_ids: row.roles.map(role => role.id),
    })
    dialogVisible.value = true
}

const save = async () => {
    if (editing.value) {
        await updateAdminUser(editing.value.id, form)
    } else {
        await createAdminUser(form)
    }
    ElMessage.success('已保存')
    dialogVisible.value = false
    await load()
}

const openPassword = (row: AdminUser) => {
    passwordTarget.value = row
    Object.assign(passwordForm, { password: '', password_confirmation: '' })
    passwordVisible.value = true
}

const savePassword = async () => {
    if (!passwordTarget.value) return
    await resetAdminPassword(passwordTarget.value.id, passwordForm)
    ElMessage.success('密码已更新')
    passwordVisible.value = false
}

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
</style>
