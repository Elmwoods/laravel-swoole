<template>

    <el-card>

        <template #header>

            Docker Containers

        </template>

        <el-table
            :data="containers"
            border
            stripe
        >

            <el-table-column
                prop="name"
                label="Container"
            />

            <el-table-column
                prop="image"
                label="Image"
            />

            <el-table-column
                prop="state"
                label="State"
            />

            <el-table-column
                prop="status"
                label="Status"
            />

            <el-table-column
                width="240"
                label="Action"
            >

                <template #default="{ row }">

                    <el-button
                        type="primary"
                        size="small"
                        @click="showStats(row)"
                    >
                        Stats
                    </el-button>

                    <el-button
                        type="warning"
                        size="small"
                        @click="showLogs(row)"
                    >
                        Logs
                    </el-button>

                    <el-button
                        type="danger"
                        size="small"
                        @click="restart(row)"
                    >
                        Restart
                    </el-button>

                </template>

            </el-table-column>

        </el-table>

    </el-card>

    <DockerLogDialog
        ref="logDialog"
    />

</template>

<script setup>

import axios from 'axios'

import { ref,onMounted } from 'vue'

import { ElMessage,ElMessageBox } from 'element-plus'

import DockerLogDialog from './DockerLogDialog.vue'

const logDialog = ref()

/**
 * 容器列表
 */
const containers = ref([])

/**
 * 获取容器
 */
const loadContainers = async () => {

    const res =
        await axios.get(
            '/api/ops/docker/containers'
        )

    containers.value =
        res.data.data
}

/**
 * 查看资源
 */
const showStats = async (row) => {

    const res =
        await axios.get(
            `/api/ops/docker/stats/${row.full_id}`
        )

    ElMessageBox.alert(
        `<pre>${
            JSON.stringify(
                res.data.data,
                null,
                2
            )
        }</pre>`,
        'Container Stats',
        {
            dangerouslyUseHTMLString:true
        }
    )
}

/**
 * 查看日志
 *
 * 改成 websocket
 */
const showLogs = (row) => {

    logDialog.value.open(
        row.full_id
    )
}

/**
 * 重启
 */
const restart = async (row) => {

    await axios.post(
        `/api/ops/docker/restart/${row.full_id}`
    )

    ElMessage.success(
        'Restart Success'
    )

    loadContainers()
}

onMounted(() => {

    loadContainers()

    /**
     * 容器列表
     * 仍保留轮询
     *
     * 日志已经改成WS
     */
    setInterval(
        loadContainers,
        10000
    )
})

</script>
