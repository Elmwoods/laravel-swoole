import { createRouter, createWebHistory } from 'vue-router'
import AdminLayout from '../layouts/AdminLayout.vue'
import Dashboard from '../pages/Dashboard.vue'
import { useAdminAuthStore } from '@/stores/adminAuth'

const routes = [
    {
        path: '/admin/login',
        name: 'AdminLogin',
        component: () => import('../pages/admin/Login.vue'),
        meta: {
            public: true,
            title: '后台登录',
        },
    },
    {
        path: '/admin/ops',
        component: AdminLayout,
        meta: {
            requiresAuth: true,
        },
        children: [
            {
                path: '',
                name: 'OpsDashboard',
                component: Dashboard,
                meta: {
                    title: '运维总览',
                    description: '核心服务、系统资源与实时指标概览',
                    permission: 'ops.dashboard.view',
                },
            },
            {
                path: 'octane',
                name: 'OctaneControl',
                component: () => import('../pages/ops/OctaneControl.vue'),
                meta: {
                    title: 'Octane',
                    description: 'Worker 数量、进程资源与 Reload 管理',
                    permission: 'ops.system.view',
                },
            },
            {
                path: 'redis',
                name: 'RedisMonitor',
                component: () => import('../pages/ops/RedisMonitor.vue'),
                meta: {
                    title: 'Redis',
                    description: '连接、内存、QPS 与持久化状态',
                    permission: 'ops.system.view',
                },
            },
            {
                path: 'redis/chart',
                name: 'RedisChart',
                component: () => import('../pages/ops/RedisChart.vue'),
                meta: {
                    title: 'Redis 趋势',
                    description: 'Redis 历史指标与趋势图',
                    permission: 'ops.system.view',
                },
            },
            {
                path: 'queue',
                name: 'QueueMonitor',
                component: () => import('../pages/ops/QueueMonitor.vue'),
                meta: {
                    title: 'Queue',
                    description: '队列堆积、失败任务与 Worker 状态',
                    permission: 'ops.system.view',
                },
            },
            {
                path: 'supervisor',
                name: 'SupervisorDashboard',
                component: () => import('../pages/ops/SupervisorDashboard.vue'),
                meta: {
                    title: 'Supervisor',
                    description: '容器内进程启停、重启与日志查看',
                    permission: 'ops.supervisor.view',
                },
            },
            {
                path: 'docker',
                name: 'DockerDashboard',
                component: () => import('../pages/ops/docker/DockerDashboard.vue'),
                meta: {
                    title: 'Docker',
                    description: '容器状态、资源快照与日志入口',
                    permission: 'ops.docker.view',
                },
            },
            {
                path: 'system/disk-monitor',
                name: 'DiskMonitor',
                component: () => import('../pages/ops/system/DiskMonitor.vue'),
                meta: {
                    title: 'Disk',
                    description: '磁盘使用率与分区状态',
                    permission: 'ops.system.view',
                },
            },
            {
                path: 'network',
                name: 'NetworkMonitor',
                component: () => import('../pages/ops/NetworkMonitor.vue'),
                meta: {
                    title: 'Network',
                    description: '实时网络吞吐与接口流量',
                    permission: 'ops.system.view',
                },
            },
            {
                path: 'logs',
                name: 'Logs',
                component: () => import('../pages/ops/logs/Logs.vue'),
                meta: {
                    title: 'Logs',
                    description: 'Laravel、Octane、Redis 与系统日志',
                    permission: 'ops.logs.view',
                },
            },
            {
                path: 'alerts',
                name: 'AlertCenter',
                component: () => import('../pages/ops/AlertCenter.vue'),
                meta: {
                    title: '告警中心',
                    description: '实时告警、确认处理、Telegram 与邮件通知',
                    permission: 'ops.alerts.view',
                },
            },
            {
                path: 'admin-users',
                name: 'AdminUsers',
                component: () => import('../pages/admin/AdminUsers.vue'),
                meta: {
                    title: '管理员',
                    description: '后台管理员账号、状态与角色绑定',
                    permission: 'admin.users.manage',
                },
            },
            {
                path: 'admin-roles',
                name: 'AdminRoles',
                component: () => import('../pages/admin/AdminRoles.vue'),
                meta: {
                    title: '角色权限',
                    description: '后台角色与权限矩阵',
                    permission: 'admin.roles.manage',
                },
            },
            {
                path: 'admin-audit-logs',
                name: 'AdminAuditLogs',
                component: () => import('../pages/admin/AdminAuditLogs.vue'),
                meta: {
                    title: '审计日志',
                    description: '后台登录、管理和运维操作记录',
                    permission: 'admin.audit.view',
                },
            },
            {
                path: 'no-permission',
                name: 'AdminNoPermission',
                component: () => import('../pages/admin/NoPermission.vue'),
                meta: {
                    title: '暂无权限',
                    description: '当前账号没有可访问的后台功能',
                },
            },
        ],
    },
    {
        path: '/',
        redirect: '/admin/ops',
    },
]

const router = createRouter({
    history: createWebHistory(),
    routes,
})

router.beforeEach(async (to) => {
    const auth = useAdminAuthStore()

    if (to.meta.public) {
        return true
    }

    if (!auth.loaded) {
        await auth.loadProfile()
    }

    if (!auth.isAuthenticated) {
        return {
            path: '/admin/login',
            query: { redirect: to.fullPath },
        }
    }

    if (to.name === 'AdminNoPermission') {
        return true
    }

    const permission = to.meta.permission

    if (typeof permission === 'string' && !auth.hasPermission(permission)) {
        return firstAllowedPath(auth.permissions)
    }

    return true
})

const firstAllowedPath = (permissions) => {
    const candidates = [
        ['ops.dashboard.view', '/admin/ops'],
        ['ops.system.view', '/admin/ops/octane'],
        ['ops.supervisor.view', '/admin/ops/supervisor'],
        ['ops.docker.view', '/admin/ops/docker'],
        ['ops.logs.view', '/admin/ops/logs'],
        ['ops.alerts.view', '/admin/ops/alerts'],
        ['admin.users.manage', '/admin/ops/admin-users'],
        ['admin.roles.manage', '/admin/ops/admin-roles'],
        ['admin.audit.view', '/admin/ops/admin-audit-logs'],
    ]
    const allowed = candidates.find(([permission]) => permissions.includes(permission))

    return allowed ? allowed[1] : '/admin/ops/no-permission'
}

export default router
