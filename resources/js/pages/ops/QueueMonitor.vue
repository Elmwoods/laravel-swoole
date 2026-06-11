<template>
    <div class="queue-monitor">

        <el-card>
            <template #header>
                Queue 实时监控
            </template>

            <el-row :gutter="20">

                <el-col :span="8">
                    <h3>Redis Queues</h3>

                    <el-table :data="queues">
                        <el-table-column prop="name" label="Queue" />
                        <el-table-column prop="pending" label="Pending Jobs" />
                    </el-table>
                </el-col>

                <el-col :span="8">
                    <h3>Failed Jobs</h3>
                    <p>总数: {{ failed.count }}</p>

                    <el-table :data="failed.latest">
                        <el-table-column prop="id" label="ID" />
                        <el-table-column prop="queue" label="Queue" />
                        <el-table-column prop="failed_at" label="Failed At" />
                    </el-table>
                </el-col>

                <el-col :span="8">
                    <h3>Worker 状态</h3>

                    <el-tag type="success" v-if="workers.octane.running">
                        Octane Running
                    </el-tag>

                    <el-tag type="danger" v-else>
                        Octane Stopped
                    </el-tag>

                    <p>Queue Driver: {{ workers.queue_connection }}</p>
                </el-col>

            </el-row>

        </el-card>

    </div>
</template>

<script setup lang="ts">
import { reactive, onMounted } from 'vue'
import axios from 'axios'

const queues = reactive<any[]>([])
const failed = reactive<any>({ count: 0, latest: [] })
const workers = reactive<any>({ octane: {}, queue_connection: '' })

const fetchData = async () => {
    const res = await axios.get('/api/ops/queue/summary')

    const data = res.data.data

    queues.splice(0, queues.length, ...data.queues)

    failed.count = data.failed_jobs.count
    failed.latest = data.failed_jobs.latest

    Object.assign(workers, data.workers)
}

onMounted(() => {
    fetchData()
    setInterval(fetchData, 5000)
})
</script>

<style scoped>
.queue-monitor {
    padding: 20px;
}
</style>
