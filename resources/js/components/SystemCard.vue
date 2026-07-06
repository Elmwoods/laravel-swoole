<template>
    <el-card>
        <template #header>
            System Monitor
        </template>

        <el-row :gutter="20">
            <el-col :span="12">
                <el-statistic
                    title="CPU"
                    :value="cpu"
                    suffix="%"
                />
            </el-col>

            <el-col :span="12">
                <el-statistic
                    title="Memory"
                    :value="memory"
                    suffix="%"
                />
            </el-col>
        </el-row>
    </el-card>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import axios from "../utils/axios"
import echo from "../utils/echo"

const cpu = ref(0)
const memory = ref(0)

let channel: any = null

/**
 * 初始化一次数据（避免空白）
 */
const init = async () => {
    try {
        const res = await axios.get('/api/ops/system/summary')

        cpu.value = res.data.data.cpu
        memory.value = res.data.data.memory.available/res.data.data.memory.total
    } catch (e) {
        console.error('init summary failed', e)
    }
}

/**
 * WebSocket 实时更新
 */
const startEcho = () => {
    if (channel) return

    channel = echo.channel('ops.system.metrics')
        .listen('.metrics.updated', (e: any) => {
            const data = e?.data

            if (!data) return
            cpu.value = data.cpu * 100

            memory.value =
                Math.round(
                    ((data.memory.total - data.memory.available)
                        / data.memory.total) * 100
                )
        })
}

onMounted(() => {
    init()
    startEcho()
})

onBeforeUnmount(() => {
    if (channel) {
        echo.leaveChannel('ops.system.summary')
        channel = null
    }
})
</script>
