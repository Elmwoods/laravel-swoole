<template>
    <div class="queue-monitor">

        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <span>Queue 实时监控</span>

                    <el-button :icon="Refresh" :loading="loading" @click="fetchData">
                        刷新
                    </el-button>
                </div>
            </template>

            <el-row :gutter="20">

                <el-col :xs="24" :md="8">
                    <h3>Redis Queues</h3>

                    <el-table :data="queues" border stripe v-loading="loading">
                        <el-table-column prop="name" label="Queue" />
                        <el-table-column prop="pending" label="Pending" />
                        <el-table-column prop="delayed" label="Delayed" />
                        <el-table-column prop="reserved" label="Reserved" />
                    </el-table>
                </el-col>

                <el-col :xs="24" :md="8">
                    <h3>Failed Jobs</h3>
                    <el-alert :closable="false" type="warning" show-icon>
                        <template #title>
                            失败任务总数：{{ failed.count }}
                        </template>
                    </el-alert>

                    <el-table :data="failed.latest" border stripe class="table-block">
                        <el-table-column prop="id" label="ID" />
                        <el-table-column prop="queue" label="Queue" />
                        <el-table-column prop="failed_at" label="Failed At" />
                    </el-table>
                </el-col>

                <el-col :xs="24" :md="8">
                    <h3>Worker 状态</h3>

                    <el-tag type="success" v-if="workers.running">
                        Worker Running
                    </el-tag>

                    <el-tag type="danger" v-else>
                        Worker Stopped
                    </el-tag>

                    <p>Queue Driver: {{ workers.queue_connection }}</p>
                    <p>Worker Processes: {{ workers.process_count }}</p>

                    <el-table :data="workers.processes" border stripe class="table-block">
                        <el-table-column prop="pid" label="PID" width="90" />
                        <el-table-column prop="cpu_percent" label="CPU %" width="90" />
                        <el-table-column prop="memory_percent" label="Memory %" width="110" />
                        <el-table-column prop="running_time" label="运行时长" width="120" />
                        <el-table-column prop="command" label="命令" min-width="220" show-overflow-tooltip />
                    </el-table>
                </el-col>

            </el-row>

        </el-card>

    </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import { getQueueSummary, type QueueMetric, type QueueSummary } from '@/api/opsCenter'

const loading = ref(false)
let timer: number | null = null

const queues = reactive<QueueMetric[]>([])
const failed = reactive<QueueSummary['failed_jobs']>({ count: 0, latest: [] })
const workers = reactive<QueueSummary['workers']>({
    queue_connection: '',
    running: false,
    process_count: 0,
    processes: [],
})

/**
 * 获取 Queue、失败任务和 worker 进程状态。
 */
const fetchData = async () => {
    loading.value = true

    try {
        const res = await getQueueSummary()
        const data = res.data.data

        queues.splice(0, queues.length, ...data.queues)
        failed.count = data.failed_jobs.count
        failed.latest = data.failed_jobs.latest
        Object.assign(workers, data.workers)
    } catch {
        ElMessage.error('Queue 状态获取失败')
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    fetchData()
    timer = window.setInterval(fetchData, 5000)
})

onBeforeUnmount(() => {
    if (timer) {
        window.clearInterval(timer)
    }
})
</script>

<style scoped>
.queue-monitor {
    padding: 0;
}

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.table-block {
    margin-top: 12px;
}
</style>
