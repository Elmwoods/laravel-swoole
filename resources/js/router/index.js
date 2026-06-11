import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from '../pages/Dashboard.vue'

const routes = [
    {
        path: '/admin/ops',
        component: Dashboard,
    },
    {
        path: '/admin/ops/redis',
        name: 'RedisMonitor',
        component: () => import('../pages/ops/RedisMonitor.vue'),
    },
    {
        path: '/admin/ops/redis/chart',
        name: 'RedisChart',
        component: () => import('../pages/ops/RedisChart.vue'),
    },
    {
        path: '/admin/ops/queue',
        name: 'QueueMonitor',
        component: () => import('../pages/ops/QueueMonitor.vue'),
    },
    {
        path: '/admin/ops/supervisor',
        component: () => import('../pages/ops/SupervisorDashboard.vue'),
    },
    {
        path: '/admin/ops/system/disk-monitor',
        component: () => import('../pages/ops/system/DiskMonitor.vue'),
    },
    {
        path: '/admin/ops/network',
        component: () => import('../pages/ops/NetworkMonitor.vue'),
    },
    {
        path: '/admin/ops/logs',
        component: () => import('../pages/ops/logs/Logs.vue'),
    },
    {
        path: '/admin/ops/docker',
        component: () => import('../pages/ops/docker/DockerDashboard.vue'),
    }
]

export default createRouter({
    history: createWebHistory(),
    routes,
})
