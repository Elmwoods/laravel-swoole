import axios from 'axios'

/**
 * 统一 Axios 实例
 * 用于 Ops Center API 请求
 */
const instance = axios.create({
    baseURL: '/',
    timeout: 10000,
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

        console.error('API Error:', error)

        return Promise.reject(error)
    }
)

export default instance
