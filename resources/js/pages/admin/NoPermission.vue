<template>
    <!-- 无权限占位页：当管理员账号未分配任何权限时展示，避免直接跳空白页 -->
    <div class="no-permission-page">
        <!-- el-result 结果页：给出提示语并引导用户联系超管 -->
        <el-result
            icon="warning"
            title="暂无可访问功能"
            sub-title="当前后台账号尚未分配任何可用权限，请联系超级管理员调整角色权限。"
        >
            <!-- extra 插槽放操作按钮：刷新权限 / 退出登录 -->
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
// 管理端鉴权 store：读取权限列表 / 触发退出登录
const auth = useAdminAuthStore()

// 重新拉取账号资料并检查权限。
// 为什么：超管可能刚刚给该账号补发了权限，重新加载后若已有权限就直接跳回工作台，
// 省去用户手动重新登录的麻烦。
const reloadProfile = async () => {
    await auth.loadProfile()

    if (auth.permissions.length > 0) {
        await router.replace('/admin/ops')
    }
}

// 退出登录：清理会话后跳回登录页。
// 为什么：无权限用户可能想换账号登录，提供明确出口。
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
