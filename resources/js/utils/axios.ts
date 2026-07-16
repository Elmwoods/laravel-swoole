import axios from 'axios'
import { ElMessage } from 'element-plus'

/**
 * 统一 Axios 实例
 * 用于 Ops Center API 请求
 */
const instance = axios.create({
    baseURL: '/',
    timeout: 10000,
    withCredentials: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Content-Type': 'application/json',
    },
})

/**
 * 请求拦截器（可扩展 token）
 */
instance.interceptors.request.use((config) => {

    // 未来可以在这里加 token
    // config.headers.Authorization = 'Bearer xxx'

    return config
})

/**
 * 响应拦截器（统一错误处理）
 */
instance.interceptors.response.use(
    (response) => {

        // 直接返回后端 data
        return response
    },
    (error) => {

        const status = error?.response?.status

        if (status === 401 && window.location.pathname !== '/admin/login') {
            window.location.href = `/admin/login?redirect=${encodeURIComponent(window.location.pathname)}`
        }

        if (status === 403) {
            ElMessage.error(error?.response?.data?.message || '没有权限执行该操作')
        }

        if (import.meta.env.DEV) {
            console.error('API request failed', {
                message: error?.message,
                status,
                url: error?.config?.url,
                method: error?.config?.method,
            })
        }

        return Promise.reject(error)
    }
)

export default instance
