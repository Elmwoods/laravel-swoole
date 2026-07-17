<template>
    <div class="security-page">
        <div class="toolbar">
            <el-button type="primary" @click="openCreate">新增管理员</el-button>
        </div>

        <el-table v-loading="loading" :data="users" border empty-text="暂无管理员">
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
            <el-table-column label="2FA" width="110">
                <template #default="{ row }">
                    <el-tag :type="row.security?.two_factor_enabled ? 'success' : 'warning'">
                        {{ row.security?.two_factor_enabled ? '已启用' : '待绑定' }}
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column label="操作" width="290">
                <template #default="{ row }">
                    <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                    <el-button v-if="auth.isSuperAdmin" link type="warning" @click="openPassword(row)">重置密码</el-button>
                    <el-button
                        v-if="auth.isSuperAdmin && row.id !== auth.profile?.admin?.id"
                        link
                        type="danger"
                        @click="resetTwoFactor(row)"
                    >
                        重置 2FA
                    </el-button>
                </template>
            </el-table-column>
        </el-table>

        <el-dialog v-model="dialogVisible" :title="editing ? '编辑管理员' : '新增管理员'" width="520px">
            <el-form :model="form" label-position="top">
                <el-form-item label="姓名"><el-input v-model="form.name" /></el-form-item>
                <el-form-item label="邮箱"><el-input v-model="form.email" /></el-form-item>
                <el-form-item v-if="!editing" label="密码"><el-input v-model="form.password" type="password" /></el-form-item>
                <el-form-item label="状态"><el-switch v-model="form.is_active" /></el-form-item>
                <el-form-item label="角色">
                    <el-select v-model="form.role_ids" multiple>
                        <el-option
                            v-for="role in roles"
                            :key="role.id"
                            :disabled="!role.is_active"
                            :label="role.name"
                            :value="role.id"
                        />
                    </el-select>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button :disabled="saving" @click="dialogVisible = false">取消</el-button>
                <el-button :loading="saving" type="primary" @click="save">保存</el-button>
            </template>
        </el-dialog>

        <el-dialog v-model="passwordVisible" title="重置密码" width="420px">
            <el-form :model="passwordForm" label-position="top">
                <el-form-item label="新密码"><el-input v-model="passwordForm.password" type="password" /></el-form-item>
                <el-form-item label="确认密码"><el-input v-model="passwordForm.password_confirmation" type="password" /></el-form-item>
            </el-form>
            <template #footer>
                <el-button :disabled="passwordSaving" @click="passwordVisible = false">取消</el-button>
                <el-button :loading="passwordSaving" type="primary" @click="savePassword">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useAdminAuthStore } from '@/stores/adminAuth'
import {
    createAdminUser,
    getAdminRoles,
    getAdminUsers,
    resetAdminPassword,
    resetAdminTwoFactor,
    updateAdminUser,
    type AdminRole,
    type AdminUser,
} from '@/api/adminSecurity'

const auth = useAdminAuthStore()
const users = ref<AdminUser[]>([])
const roles = ref<AdminRole[]>([])
const dialogVisible = ref(false)
const passwordVisible = ref(false)
const loading = ref(false)
const saving = ref(false)
const passwordSaving = ref(false)
const editing = ref<AdminUser | null>(null)
const passwordTarget = ref<AdminUser | null>(null)
const form = reactive<any>({ name: '', email: '', password: '', is_active: true, role_ids: [] })
const passwordForm = reactive({ password: '', password_confirmation: '' })

const load = async () => {
    loading.value = true

    try {
        const [userRes, roleRes] = await Promise.all([getAdminUsers(), getAdminRoles()])
        users.value = userRes.data.data.items
        roles.value = roleRes.data.data.roles
    } finally {
        loading.value = false
    }
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
    if (saving.value) return

    saving.value = true

    try {
        if (editing.value) {
            await updateAdminUser(editing.value.id, form)
        } else {
            await createAdminUser(form)
        }
        ElMessage.success('已保存')
        dialogVisible.value = false
        await load()
    } finally {
        form.password = ''
        saving.value = false
    }
}

const openPassword = (row: AdminUser) => {
    passwordTarget.value = row
    Object.assign(passwordForm, { password: '', password_confirmation: '' })
    passwordVisible.value = true
}

const savePassword = async () => {
    if (!passwordTarget.value || passwordSaving.value) return

    passwordSaving.value = true

    try {
        await resetAdminPassword(passwordTarget.value.id, passwordForm)
        ElMessage.success('密码已更新')
        passwordVisible.value = false
    } finally {
        Object.assign(passwordForm, { password: '', password_confirmation: '' })
        passwordSaving.value = false
    }
}

const resetTwoFactor = async (row: AdminUser) => {
    if (loading.value) return

    loading.value = true

    try {
        await resetAdminTwoFactor(row.id)
        ElMessage.success('2FA 已重置，该管理员下次登录需要重新绑定')
        await load()
    } finally {
        loading.value = false
    }
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
