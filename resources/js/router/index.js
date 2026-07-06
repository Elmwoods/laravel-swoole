import { createRouter, createWebHistory } from 'vue-router'
import AdminLayout from '../layouts/AdminLayout.vue'
import Dashboard from '../pages/Dashboard.vue'

const routes = [
    {
        path: '/admin/ops',
        component: AdminLayout,
        children: [
            {
                path: '',
                name: 'OpsDashboard',
                component: Dashboard,
                meta: {
                    title: '运维总览',
                    description: '核心服务、系统资源与实时指标概览',
                },
            },
            {
                path: 'octane',
                name: 'OctaneControl',
                component: () => import('../pages/ops/OctaneControl.vue'),
                meta: {
                    title: 'Octane',
                    description: 'Worker 数量、进程资源与 Reload 管理',
                },
            },
            {
                path: 'redis',
                name: 'RedisMonitor',
                component: () => import('../pages/ops/RedisMonitor.vue'),
                meta: {
                    title: 'Redis',
                    description: '连接、内存、QPS 与持久化状态',
                },
            },
            {
                path: 'redis/chart',
                name: 'RedisChart',
                component: () => import('../pages/ops/RedisChart.vue'),
                meta: {
                    title: 'Redis 趋势',
                    description: 'Redis 历史指标与趋势图',
                },
            },
            {
                path: 'queue',
                name: 'QueueMonitor',
                component: () => import('../pages/ops/QueueMonitor.vue'),
                meta: {
                    title: 'Queue',
                    description: '队列堆积、失败任务与 Worker 状态',
                },
            },
            {
                path: 'supervisor',
                name: 'SupervisorDashboard',
                component: () => import('../pages/ops/SupervisorDashboard.vue'),
                meta: {
                    title: 'Supervisor',
                    description: '容器内进程启停、重启与日志查看',
                },
            },
            {
                path: 'docker',
                name: 'DockerDashboard',
                component: () => import('../pages/ops/docker/DockerDashboard.vue'),
                meta: {
                    title: 'Docker',
                    description: '容器状态、资源快照与日志入口',
                },
            },
            {
                path: 'system/disk-monitor',
                name: 'DiskMonitor',
                component: () => import('../pages/ops/system/DiskMonitor.vue'),
                meta: {
                    title: 'Disk',
                    description: '磁盘使用率与分区状态',
                },
            },
            {
                path: 'network',
                name: 'NetworkMonitor',
                component: () => import('../pages/ops/NetworkMonitor.vue'),
                meta: {
                    title: 'Network',
                    description: '实时网络吞吐与接口流量',
                },
            },
            {
                path: 'logs',
                name: 'Logs',
                component: () => import('../pages/ops/logs/Logs.vue'),
                meta: {
                    title: 'Logs',
                    description: 'Laravel、Octane、Redis 与系统日志',
                },
            },
            {
                path: 'alerts',
                name: 'AlertCenter',
                component: () => import('../pages/ops/AlertCenter.vue'),
                meta: {
                    title: '告警中心',
                    description: '实时告警、确认处理、Telegram 与邮件通知',
                },
            },
        ],
    },
    {
        path: '/',
        redirect: '/admin/ops',
    }
]

export default createRouter({
    history: createWebHistory(),
    routes,
})
