// @ts-ignore
import request from '@/utils/request'

/**
 * 获取 Dashboard
 */
export const getDashboard = () => {

    return request.get('/api/ops/dashboard')

}

/**
 * Reload Octane Worker
 */
export const reloadOctane = () => {

    return request.post('/api/ops/octane/reload')

}

/**
 * Stop Octane
 */
export const stopOctane = () => {

    return request.post('/api/ops/octane/stop')

}
