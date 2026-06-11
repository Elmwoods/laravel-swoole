<template>

    <el-card>

        <template #header>
            Supervisor Dashboard
        </template>

        <el-table
            :data="services"
            border
            stripe
            v-loading="loading"
        >

            <el-table-column
                prop="name"
                label="Service"
            />

            <el-table-column
                prop="status"
                label="Status"
            >
                <template #default="{ row }">

                    <el-tag
                        v-if="row.status === 'RUNNING'"
                        type="success"
                    >
                        RUNNING
                    </el-tag>

                    <el-tag
                        v-else
                        type="danger"
                    >
                        {{ row.status }}
                    </el-tag>

                </template>
            </el-table-column>

            <el-table-column
                prop="description"
                label="Description"
            />

            <el-table-column
                label="Actions"
                width="320"
            >

                <template #default="{ row }">

                    <el-space>

                        <el-button
                            size="small"
                            type="success"
                            @click="start(row)"
                        >
                            Start
                        </el-button>

                        <el-button
                            size="small"
                            type="warning"
                            @click="restart(row)"
                        >
                            Restart
                        </el-button>

                        <el-button
                            size="small"
                            type="danger"
                            @click="stop(row)"
                        >
                            Stop
                        </el-button>

                        <el-button
                            size="small"
                            @click="showLogs(row)"
                        >
                            Logs
                        </el-button>

                    </el-space>

                </template>

            </el-table-column>

        </el-table>

    </el-card>

    <el-dialog
        v-model="logVisible"
        width="80%"
        title="Service Logs"
    >

    <pre class="log-viewer">
{{ logContent }}
    </pre>

    </el-dialog>

</template>

<script setup lang="ts">

import axios from 'axios'
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'

const services = ref([])
const loading = ref(false)

const logVisible = ref(false)
const logContent = ref('')

const load = async () => {

    loading.value = true

    try {

        const res = await axios.get(
            '/api/ops/supervisor/status'
        )

        services.value =
            res.data.data.services

    } finally {

        loading.value = false
    }
}

const start = async (row:any) => {

    await axios.post(
        `/api/ops/supervisor/start/${row.name}`
    )

    ElMessage.success('Started')

    load()
}

const stop = async (row:any) => {

    await axios.post(
        `/api/ops/supervisor/stop/${row.name}`
    )

    ElMessage.success('Stopped')

    load()
}

const restart = async (row:any) => {

    await axios.post(
        `/api/ops/supervisor/restart/${row.name}`
    )

    ElMessage.success('Restarted')

    load()
}

const showLogs = async (row:any) => {

    const res = await axios.get(
        `/api/ops/supervisor/logs/${row.name}`
    )

    logContent.value =
        res.data.data.logs

    logVisible.value = true
}

onMounted(() => {

    load()

    setInterval(load, 5000)
})

</script>

<style scoped>

.log-viewer {

    height: 600px;
    overflow: auto;

    background: #111;

    color: #0f0;

    padding: 20px;

    white-space: pre-wrap;
}

</style>
