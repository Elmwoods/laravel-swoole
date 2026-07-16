<template>
    <section class="octane-page">
        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <span>Octane Worker 监控</span>

                    <el-space>
                        <el-button :icon="Refresh" :loading="loading" @click="fetch">
                            刷新
                        </el-button>

                        <el-button type="primary" :icon="Switch" :loading="actionLoading" @click="reload">
                            Reload
                        </el-button>

                        <el-button type="danger" :icon="RefreshRight" :loading="actionLoading" @click="restart">
                            Restart
                        </el-button>
                    </el-space>
                </div>
            </template>

            <el-row :gutter="16" class="metric-row">
                <el-col :xs="24" :sm="12" :md="6">
                    <el-statistic title="运行状态" :value="data.running ? 'RUNNING' : 'STOPPED'" />
                </el-col>

                <el-col :xs="24" :sm="12" :md="6">
                    <el-statistic title="配置 Worker" :value="data.configured_workers" />
                </el-col>

                <el-col :xs="24" :sm="12" :md="6">
                    <el-statistic title="Task Worker" :value="data.configured_task_workers" />
                </el-col>

                <el-col :xs="24" :sm="12" :md="6">
                    <el-statistic title="进程数量" :value="data.process_count" />
                </el-col>
            </el-row>

            <el-descriptions :column="2" border class="detail">
                <el-descriptions-item label="Server">
                    {{ data.server }}
                </el-descriptions-item>

                <el-descriptions-item label="Master PID">
                    {{ data.master_pid || '-' }}
                </el-descriptions-item>

                <el-descriptions-item label="State File">
                    <el-tag :type="data.state_file.exists ? 'success' : 'warning'">
                        {{ data.state_file.exists ? '存在' : '未生成' }}
                    </el-tag>
                </el-descriptions-item>

                <el-descriptions-item label="检查时间">
                    {{ data.checked_at || '-' }}
                </el-descriptions-item>
            </el-descriptions>

            <el-table :data="data.workers" border stripe class="worker-table" v-loading="loading">
                <el-table-column prop="pid" label="PID" width="110" />
                <el-table-column prop="parent_pid" label="PPID" width="110" />
                <el-table-column prop="cpu_percent" label="CPU %" width="110" />
                <el-table-column prop="memory_percent" label="Memory %" width="120" />
                <el-table-column prop="running_time" label="运行时长" width="130" />
                <el-table-column prop="command" label="命令" min-width="360" show-overflow-tooltip />
            </el-table>
        </el-card>
    </section>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh, RefreshRight, Switch } from '@element-plus/icons-vue'
import {
    getOctaneStatus,
    reloadOctane,
    restartOctane,
    type OctaneStatus,
} from '@/api/opsCenter'

const loading = ref(false)
const actionLoading = ref(false)
let timer: number | null = null

const data = reactive<OctaneStatus>({
    running: false,
    server: 'swoole',
    configured_workers: 0,
    configured_task_workers: 0,
    master_pid: null,
    process_count: 0,
    workers: [],
    state_file: {
        path: '',
        exists: false,
    },
    checked_at: '',
})

/**
 * 获取 Octane Worker 状态。
 */
const fetch = async () => {
    loading.value = true

    try {
        const res = await getOctaneStatus()
        Object.assign(data, res.data.data)
    } finally {
        loading.value = false
    }
}

/**
 * 高风险控制确认。
 */
const askConfirm = async (action: string) => {
    try {
        const { value } = await ElMessageBox.prompt(
            `请输入 CONFIRM 确认${action}`,
            '高风险操作确认',
            {
                confirmButtonText: '确认执行',
                cancelButtonText: '取消',
                inputPattern: /^CONFIRM$/,
                inputErrorMessage: '确认短语必须为 CONFIRM',
                type: 'warning',
            },
        )

        return value
    } catch {
        return null
    }
}

/**
 * 平滑重载 Octane Worker。
 */
const reload = async () => {
    const confirmText = await askConfirm('Reload Octane')
    if (!confirmText) return

    actionLoading.value = true

    try {
        await reloadOctane(confirmText)
        ElMessage.success('Octane Reload 已发送')
        await fetch()
    } finally {
        actionLoading.value = false
    }
}

/**
 * 重启 Octane。
 */
const restart = async () => {
    const confirmText = await askConfirm('Restart Octane')
    if (!confirmText) return

    actionLoading.value = true

    try {
        await restartOctane(confirmText)
        ElMessage.success('Octane Restart 已发送')
        await fetch()
    } finally {
        actionLoading.value = false
    }
}

onMounted(() => {
    fetch()
    timer = window.setInterval(fetch, 5000)
})

onBeforeUnmount(() => {
    if (timer) {
        window.clearInterval(timer)
    }
})
</script>

<style scoped>
.octane-page {
    padding: 0;
}

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.metric-row,
.detail,
.worker-table {
    margin-top: 16px;
}
</style>
