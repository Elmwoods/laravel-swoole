// @ts-ignore
// Ops Center 基础运维 API：仪表盘概览与 Octane 服务的重载/停止
import request from '@/utils/request'

/**
 * 获取 Dashboard
 * GET /api/ops/dashboard —— 拉取运维仪表盘的聚合概览数据
 */
export const getDashboard = () => {

    return request.get('/api/ops/dashboard')

}

/**
 * Reload Octane Worker
 * POST /api/ops/octane/reload —— 平滑重载 Octane 工作进程；confirmText 为二次确认文本，防止误操作
 */
export const reloadOctane = (confirmText: string) => {

    return request.post('/api/ops/octane/reload', {
        confirm_text: confirmText,
    })

}

/**
 * Stop Octane
 * POST /api/ops/octane/stop —— 停止 Octane 服务；confirmText 为二次确认文本，防止误操作
 */
export const stopOctane = (confirmText: string) => {

    return request.post('/api/ops/octane/stop', {
        confirm_text: confirmText,
    })

}
