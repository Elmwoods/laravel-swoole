import { ref } from 'vue'
import { getDashboard } from '@/api/ops'

/**
 * Dashboard 数据状态管理（生产级写法）
 */
export function useDashboard() {

    const loading = ref(false)
    const data = ref<any>(null)

    const fetch = async () => {
        loading.value = true

        try {
            const res = await getDashboard()
            data.value = res.data.data
        } finally {
            loading.value = false
        }
    }

    return {
        data,
        loading,
        fetch
    }
}
