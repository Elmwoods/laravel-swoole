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
export const reloadOctane = (confirmText: string) => {

    return request.post('/api/ops/octane/reload', {
        confirm_text: confirmText,
    })

}

/**
 * Stop Octane
 */
export const stopOctane = (confirmText: string) => {

    return request.post('/api/ops/octane/stop', {
        confirm_text: confirmText,
    })

}
