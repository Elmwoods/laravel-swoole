import axios from 'axios'
import { ElMessage } from 'element-plus'

const request = axios.create({
    timeout: 10000,
    withCredentials: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Content-Type': 'application/json',
    },
})

request.interceptors.response.use(
    response => response,
    error => {
        const status = error?.response?.status

        if (status === 401 && window.location.pathname !== '/admin/login') {
            window.location.href = `/admin/login?redirect=${encodeURIComponent(window.location.pathname)}`
        }

        if (status === 403) {
            ElMessage.error(error?.response?.data?.message || '没有权限执行该操作')
        }

        console.error(error)
        return Promise.reject(error)
    }
)

export default request
