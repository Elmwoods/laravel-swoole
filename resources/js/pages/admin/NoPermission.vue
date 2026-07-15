<template>
    <div class="no-permission-page">
        <el-result
            icon="warning"
            title="暂无可访问功能"
            sub-title="当前后台账号尚未分配任何可用权限，请联系超级管理员调整角色权限。"
        >
            <template #extra>
                <el-button type="primary" @click="reloadProfile">刷新权限</el-button>
                <el-button @click="logout">退出登录</el-button>
            </template>
        </el-result>
    </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'

const router = useRouter()
const auth = useAdminAuthStore()

const reloadProfile = async () => {
    await auth.loadProfile()

    if (auth.permissions.length > 0) {
        await router.replace('/admin/ops')
    }
}

const logout = async () => {
    await auth.logout()
    await router.replace('/admin/login')
}
</script>

<style scoped>
.no-permission-page {
    align-items: center;
    display: flex;
    min-height: calc(100vh - 120px);
    justify-content: center;
}
</style>
