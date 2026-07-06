<template>

    <el-card shadow="never">

        <template #header>
            <div class="card-header">
                <span>Supervisor 状态管理</span>

                <el-button :icon="Refresh" :loading="loading" @click="load">
                    刷新
                </el-button>
            </div>
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
                            :icon="VideoPlay"
                            @click="start(row)"
                        >
                            Start
                        </el-button>

                        <el-button
                            size="small"
                            type="warning"
                            :icon="RefreshRight"
                            @click="restart(row)"
                        >
                            Restart
                        </el-button>

                        <el-button
                            size="small"
                            type="danger"
                            :icon="VideoPause"
                            @click="stop(row)"
                        >
                            Stop
                        </el-button>

                        <el-button
                            size="small"
                            :icon="Document"
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

import { onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Document, Refresh, RefreshRight, VideoPause, VideoPlay } from '@element-plus/icons-vue'
import {
    getSupervisorLogs,
    getSupervisorStatus,
    restartSupervisor,
    startSupervisor,
    stopSupervisor,
    type SupervisorService,
} from '@/api/opsCenter'

const services = ref<SupervisorService[]>([])
const loading = ref(false)
let timer: number | null = null

const logVisible = ref(false)
const logContent = ref('')

const load = async () => {

    loading.value = true

    try {

        const res = await getSupervisorStatus()

        services.value =
            res.data.data.services

    } finally {

        loading.value = false
    }
}

const start = async (row: SupervisorService) => {

    await startSupervisor(row.name)

    ElMessage.success('Started')

    load()
}

const stop = async (row: SupervisorService) => {

    await stopSupervisor(row.name)

    ElMessage.success('Stopped')

    load()
}

const restart = async (row: SupervisorService) => {

    await restartSupervisor(row.name)

    ElMessage.success('Restarted')

    load()
}

const showLogs = async (row: SupervisorService) => {

    const res = await getSupervisorLogs(row.name)

    logContent.value =
        res.data.data.logs

    logVisible.value = true
}

onMounted(() => {

    load()

    timer = window.setInterval(load, 5000)
})

onBeforeUnmount(() => {

    if (timer) {
        window.clearInterval(timer)
    }
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

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

</style>
