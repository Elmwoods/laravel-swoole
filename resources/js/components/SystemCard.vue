<template>
    <el-card v-loading="loading" shadow="never" class="system-card">
        <template #header>
            <div class="card-header">
                <span>系统概览</span>
                <el-button size="small" text @click="init">重试</el-button>
            </div>
        </template>

        <el-alert
            v-if="errorMessage"
            :title="errorMessage"
            type="warning"
            show-icon
            :closable="false"
            class="state-alert"
        />

        <el-row :gutter="20" class="metric-row">
            <el-col :xs="24" :sm="12">
                <el-statistic
                    title="Load 1m"
                    :value="cpu"
                />
            </el-col>

            <el-col :xs="24" :sm="12">
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
const loading = ref(false)
const errorMessage = ref('')

let channel: any = null

/**
 * 初始化一次数据（避免空白）
 */
const init = async () => {
    loading.value = true
    errorMessage.value = ''

    try {
        const res = await axios.get('/api/ops/system/summary')
        const data = res.data.data

        cpu.value = Number(Number(data.cpu ?? 0).toFixed(2))

        const total = Number(data.memory?.total ?? 0)
        const available = Number(data.memory?.available ?? 0)
        memory.value = total > 0
            ? Number((((total - available) / total) * 100).toFixed(2))
            : 0
    } catch (e) {
        errorMessage.value = '系统概览加载失败，请稍后重试。'
    } finally {
        loading.value = false
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
            cpu.value = Number(Number(data.cpu ?? 0).toFixed(2))

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
        echo.leaveChannel('ops.system.metrics')
        channel = null
    }
})
</script>

<style scoped>
.system-card {
    border-radius: 8px;
}

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.state-alert {
    margin-bottom: 14px;
}

.metric-row {
    row-gap: 16px;
}
</style>
