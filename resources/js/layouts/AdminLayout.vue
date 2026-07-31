<template>
    <!-- 运维后台整体骨架：左侧固定侧边导航 + 右侧头部与内容区 -->
    <el-container class="ops-shell">
        <!-- 侧边栏：品牌标识 + 权限过滤后的导航菜单 -->
        <el-aside class="ops-sidebar" width="248px">
            <!-- 品牌区：Logo 缩写与产品名 -->
            <div class="brand">
                <div class="brand-mark">OC</div>

                <div>
                    <div class="brand-title">Ops Center</div>
                    <div class="brand-subtitle">Laravel Octane</div>
                </div>
            </div>

            <!-- 主导航菜单：default-active 高亮当前路由，router 模式点击即路由跳转 -->
            <el-menu
                :default-active="activePath"
                class="ops-menu"
                router
            >
                <!-- 每个菜单项都按对应权限点 v-if 显隐，无权限则整条不渲染 -->
                <el-menu-item v-if="hasPermission('ops.dashboard.view')" index="/admin/ops">
                    <el-icon><Monitor /></el-icon>
                    <span>运维总览</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.security.view')" index="/admin/ops/security">
                    <el-icon><View /></el-icon>
                    <span>安全总览</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.system.view')" index="/admin/ops/octane">
                    <el-icon><Cpu /></el-icon>
                    <span>Octane</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.release.view')" index="/admin/ops/release-check">
                    <el-icon><Document /></el-icon>
                    <span>发布自检</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.inspections.view')" index="/admin/ops/inspection">
                    <el-icon><Operation /></el-icon>
                    <span>自动巡检</span>
                </el-menu-item>

                <!-- Redis 分组：折叠子菜单，含监控与趋势两个页面 -->
                <el-sub-menu v-if="hasPermission('ops.system.view')" index="redis">
                    <template #title>
                        <el-icon><Coin /></el-icon>
                        <span>Redis</span>
                    </template>

                    <el-menu-item index="/admin/ops/redis">
                        <el-icon><Coin /></el-icon>
                        <span>Redis 监控</span>
                    </el-menu-item>

                    <el-menu-item index="/admin/ops/redis/trend">
                        <el-icon><DataLine /></el-icon>
                        <span>Redis 趋势</span>
                    </el-menu-item>
                </el-sub-menu>

                <el-menu-item v-if="hasPermission('ops.system.view')" index="/admin/ops/queue">
                    <el-icon><List /></el-icon>
                    <span>Queue</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.supervisor.view')" index="/admin/ops/supervisor">
                    <el-icon><Operation /></el-icon>
                    <span>Supervisor</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.docker.view')" index="/admin/ops/docker">
                    <el-icon><Box /></el-icon>
                    <span>Docker</span>
                </el-menu-item>

                <!-- 系统资源分组：网络流量、磁盘监控、系统趋势 -->
                <el-sub-menu v-if="hasPermission('ops.system.view')" index="system">
                    <template #title>
                        <el-icon><DataLine /></el-icon>
                        <span>系统资源</span>
                    </template>

                    <el-menu-item index="/admin/ops/network">
                        <el-icon><Connection /></el-icon>
                        <span>网络流量</span>
                    </el-menu-item>

                    <el-menu-item index="/admin/ops/system/disk-monitor">
                        <el-icon><FolderOpened /></el-icon>
                        <span>磁盘监控</span>
                    </el-menu-item>

                    <el-menu-item index="/admin/ops/system/trend">
                        <el-icon><DataLine /></el-icon>
                        <span>系统趋势</span>
                    </el-menu-item>
                </el-sub-menu>

                <el-menu-item v-if="hasPermission('ops.logs.view')" index="/admin/ops/logs">
                    <el-icon><Document /></el-icon>
                    <span>日志中心</span>
                </el-menu-item>

                <!-- 告警中心：菜单右侧带未处理告警数量红色徽标，仅在有未处理告警时显示 -->
                <el-menu-item v-if="hasPermission('ops.alerts.view')" index="/admin/ops/alerts">
                    <el-icon><Bell /></el-icon>
                    <span class="menu-label">
                        <span>告警中心</span>
                        <!-- openAlertCount 为 0 时不渲染徽标，超过 99 显示 99+ -->
                        <el-badge
                            v-if="openAlertCount > 0"
                            :value="openAlertCount"
                            :max="99"
                            type="danger"
                        />
                    </span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.alerts.view')" index="/admin/ops/alerts-sla">
                    <el-icon><Timer /></el-icon>
                    <span>告警 SLA</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.alerts.view')" index="/admin/ops/alerts-silence">
                    <el-icon><MuteNotification /></el-icon>
                    <span>告警静默</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.alerts.view')" index="/admin/ops/alerts-oncall">
                    <el-icon><Calendar /></el-icon>
                    <span>值班排班</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.alerts.view')" index="/admin/ops/alerts-oncall-dashboard">
                    <el-icon><Odometer /></el-icon>
                    <span>值班仪表盘</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.alerts.view')" index="/admin/ops/alerts-heatmap">
                    <el-icon><Grid /></el-icon>
                    <span>告警热力图</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.alerts.view')" index="/admin/ops/alerts-handover">
                    <el-icon><Switch /></el-icon>
                    <span>值班交接</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.alerts.view')" index="/admin/ops/alerts-workload">
                    <el-icon><DataAnalysis /></el-icon>
                    <span>值班绩效</span>
                </el-menu-item>

                <el-menu-item v-if="hasPermission('ops.alerts.view')" index="/admin/ops/alerts-topology">
                    <el-icon><Connection /></el-icon>
                    <span>依赖拓扑</span>
                </el-menu-item>

                <!-- 安全管理分组：只要拥有其中任一子权限即展示该分组，子项再各自按权限显隐 -->
                <el-sub-menu v-if="hasAnyPermission(['admin.users.manage', 'admin.roles.manage', 'admin.audit.view', 'admin.security.manage'])" index="security">
                    <template #title>
                        <el-icon><Lock /></el-icon>
                        <span>安全管理</span>
                    </template>

                    <el-menu-item v-if="hasPermission('admin.users.manage')" index="/admin/ops/admin-users">
                        <el-icon><User /></el-icon>
                        <span>管理员</span>
                    </el-menu-item>

                    <el-menu-item v-if="hasPermission('admin.roles.manage')" index="/admin/ops/admin-roles">
                        <el-icon><Key /></el-icon>
                        <span>角色权限</span>
                    </el-menu-item>

                    <el-menu-item v-if="hasPermission('admin.audit.view')" index="/admin/ops/admin-audit-logs">
                        <el-icon><Tickets /></el-icon>
                        <span>审计日志</span>
                    </el-menu-item>

                    <el-menu-item v-if="hasPermission('admin.security.manage')" index="/admin/ops/admin-ip-rules">
                        <el-icon><Location /></el-icon>
                        <span>登录准入</span>
                    </el-menu-item>
                </el-sub-menu>

                <el-menu-item index="/admin/ops/account-security">
                    <el-icon><Avatar /></el-icon>
                    <span>账号安全</span>
                </el-menu-item>
            </el-menu>
        </el-aside>

        <!-- 右侧主区：顶部信息栏 + 路由内容区 -->
        <el-container class="ops-main">
            <!-- 顶部栏：左侧标题/副标题随路由 meta 变化，右侧为当前管理员信息与退出 -->
            <el-header class="ops-header">
                <div>
                    <!-- 标题与描述均取自当前路由的 meta -->
                    <div class="page-title">{{ pageTitle }}</div>
                    <div class="page-description">{{ pageDescription }}</div>
                </div>

                <el-space>
                    <!-- 当前登录管理员姓名 -->
                    <span class="admin-name">{{ auth.profile?.admin?.name }}</span>
                    <el-tag type="success" effect="plain">Swoole</el-tag>
                    <el-tag type="info" effect="plain">Docker Sail</el-tag>
                    <!-- 退出登录，清理会话后跳回登录页 -->
                    <el-button text type="primary" @click="logout">退出</el-button>
                </el-space>
            </el-header>

            <!-- 内容区：子路由页面在此渲染 -->
            <el-main class="ops-content">
                <router-view />
            </el-main>
        </el-container>
    </el-container>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getAlertSummary } from '@/api/opsStage4'
import { useAdminAuthStore } from '@/stores/adminAuth'
import echo from '@/utils/echo'
import {
    Avatar,
    Bell,
    Box,
    Coin,
    Connection,
    Cpu,
    DataLine,
    Calendar,
    Document,
    FolderOpened,
    Key,
    List,
    Location,
    Lock,
    Monitor,
    MuteNotification,
    DataAnalysis,
    Grid,
    Odometer,
    Switch,
    Operation,
    Tickets,
    Timer,
    User,
    View,
} from '@element-plus/icons-vue'

// 当前路由对象，用于读取 path 与 meta
const route = useRoute()
// 路由实例，用于退出后跳转
const router = useRouter()
// 管理员鉴权 store：提供 profile、权限判断与登出
const auth = useAdminAuthStore()
// 未处理告警数量，驱动侧边栏红色徽标
const openAlertCount = ref(0)
// 告警实时频道句柄，卸载时退订
let alertChannel: any = null
// 定时轮询计时器句柄，作为 WebSocket 的兜底刷新
let alertCountTimer: number | null = null

/**
 * 当前激活菜单路径。
 */
const activePath = computed(() => route.path)

/**
 * 顶部标题来自路由 meta，便于新增页面时集中维护。
 */
const pageTitle = computed(() => route.meta.title || '运维总览')

/**
 * 顶部副标题来自路由 meta。
 */
const pageDescription = computed(() => route.meta.description || 'Ops Center 企业级运维后台')

/**
 * 加载未处理告警数量。
 *
 * 侧边栏只展示计数，不拉取告警详情，避免布局组件承担过多业务数据。
 */
const loadAlertCount = async () => {
    if (!hasPermission('ops.alerts.view')) {
        return
    }

    try {
        const res = await getAlertSummary()
        openAlertCount.value = res.data.data.open_total
    } catch {
        openAlertCount.value = 0
    }
}

/**
 * 监听告警轻量事件，刷新侧边栏计数。
 */
const startAlertRealtime = () => {
    if (!hasPermission('ops.alerts.view')) {
        return
    }

    if (alertChannel) {
        return
    }

    alertChannel = echo.channel('ops.alerts')
        .listen('.alert.triggered', loadAlertCount)
        .error(() => {
            alertChannel = null
        })
}

/**
 * 同页内告警操作完成后刷新数量。
 *
 * AlertCenter 页面确认、恢复或手动评估告警后会派发该事件，避免侧边栏等到
 * WebSocket 或下一轮刷新才更新，保持页面状态一致。
 */
const handleLocalAlertUpdate = () => {
    loadAlertCount()
}

// 单个权限判断，转发给 store，供模板 v-if 使用
const hasPermission = (permission: string): boolean => auth.hasPermission(permission)

// 任一权限判断：用于父级分组，只要命中列表中任意权限即展示
const hasAnyPermission = (permissions: string[]): boolean => permissions.some(hasPermission)

// 退出登录：清理会话后跳回登录页
const logout = async () => {
    await auth.logout()
    await router.replace('/admin/login')
}

// 挂载：确保已加载用户档案 → 首次拉取告警数 → 开启实时与轮询双通道刷新
onMounted(async () => {
    // 档案未加载则先加载，保证权限判断可用
    if (!auth.loaded) {
        await auth.loadProfile()
    }

    await loadAlertCount()
    startAlertRealtime()
    // 监听同页内的告警变更事件，实现本地即时刷新
    window.addEventListener('ops:alerts-updated', handleLocalAlertUpdate)
    // 每 30 秒兜底轮询一次，防止 WebSocket 断连时计数长期失真
    alertCountTimer = window.setInterval(loadAlertCount, 30000)
})

// 卸载：退订频道、清除定时器、移除事件监听，全面回收资源防止泄漏
onBeforeUnmount(() => {
    if (alertChannel) {
        echo.leaveChannel('ops.alerts')
        alertChannel = null
    }

    if (alertCountTimer) {
        window.clearInterval(alertCountTimer)
        alertCountTimer = null
    }

    window.removeEventListener('ops:alerts-updated', handleLocalAlertUpdate)
})
</script>

<style scoped>
.ops-shell {
    background: #f4f6f8;
    height: 100vh;
    min-height: 100vh;
    overflow: hidden;
}

.ops-sidebar {
    background: #111827;
    border-right: 1px solid #1f2937;
    color: #fff;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    height: 100vh;
    min-height: 100vh;
    overflow: hidden;
}

.brand {
    align-items: center;
    display: flex;
    gap: 12px;
    height: 72px;
    padding: 0 20px;
}

.brand-mark {
    align-items: center;
    background: #22c55e;
    border-radius: 8px;
    color: #052e16;
    display: flex;
    font-size: 14px;
    font-weight: 800;
    height: 36px;
    justify-content: center;
    width: 36px;
}

.brand-title {
    font-size: 16px;
    font-weight: 700;
}

.brand-subtitle {
    color: #9ca3af;
    font-size: 12px;
    margin-top: 2px;
}

.ops-menu {
    background: transparent;
    border-right: 0;
    flex: 1;
    overflow-y: auto;
    padding: 8px 10px 20px;
}

.ops-menu :deep(.el-menu) {
    background: transparent;
}

.ops-menu :deep(.el-menu-item),
.ops-menu :deep(.el-sub-menu__title) {
    border-radius: 6px;
    color: #d1d5db;
    height: 44px;
    line-height: 44px;
    margin-bottom: 4px;
}

/* 隐藏子菜单右侧悬空的展开箭头（短标签下与文字脱节，视觉杂乱）。 */
.ops-menu :deep(.el-sub-menu__icon-arrow) {
    display: none;
}

.ops-menu :deep(.el-menu-item.is-active) {
    background: #2563eb;
    color: #fff;
}

.ops-menu :deep(.el-menu-item:hover),
.ops-menu :deep(.el-sub-menu__title:hover) {
    background: #1f2937;
    color: #fff;
}

.menu-label {
    align-items: center;
    display: flex;
    gap: 8px;
    justify-content: space-between;
    min-width: 0;
    width: 100%;
}

.menu-label :deep(.el-badge__content) {
    border: 0;
    box-shadow: 0 0 0 1px rgba(17, 24, 39, 0.2);
}

.ops-main {
    height: 100vh;
    min-width: 0;
    overflow: hidden;
}

.ops-header {
    align-items: center;
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    height: 72px;
    justify-content: space-between;
    padding: 0 28px;
}

.page-title {
    color: #111827;
    font-size: 20px;
    font-weight: 700;
}

.page-description {
    color: #6b7280;
    font-size: 13px;
    margin-top: 4px;
}

.admin-name {
    color: #334155;
    font-size: 13px;
    max-width: 140px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.ops-content {
    height: calc(100vh - 72px);
    overflow-x: hidden;
    overflow-y: auto;
    padding: 24px;
}

@media (max-width: 900px) {
    .ops-shell {
        display: block;
        height: auto;
        overflow: visible;
    }

    .ops-sidebar {
        height: auto;
        min-height: auto;
        overflow: visible;
        width: 100% !important;
    }

    .ops-menu {
        max-height: 360px;
    }

    .ops-main {
        height: auto;
        overflow: visible;
    }

    .brand {
        height: 64px;
    }

    .ops-header {
        align-items: flex-start;
        flex-direction: column;
        gap: 10px;
        height: auto;
        padding: 16px 18px;
    }

    .ops-content {
        height: auto;
        overflow: visible;
        padding: 16px;
    }
}
</style>
