<template>
    <div class="redis-monitor">

        <el-card>
            <template #header>
                <span>Redis 实时监控</span>
                <el-button style="float:right" size="small" @click="fetchData">
                    刷新
                </el-button>
            </template>

            <el-row :gutter="20">

                <el-col :span="6">
                    <el-statistic title="Redis版本" :value="state.redis_version" />
                </el-col>

                <el-col :span="6">
                    <el-statistic title="连接客户端" :value="state.connected_clients" />
                </el-col>

                <el-col :span="6">
                    <el-statistic title="QPS" :value="state.instantaneous_ops_per_sec" />
                </el-col>

                <el-col :span="6">
                    <el-statistic title="运行天数" :value="state.uptime_in_days" />
                </el-col>

            </el-row>

            <el-divider />

            <el-descriptions title="内存信息" :column="2" border>
                <el-descriptions-item label="使用内存">
                    {{ state.used_memory_human }}
                </el-descriptions-item>

                <el-descriptions-item label="峰值内存">
                    {{ state.used_memory_peak_human }}
                </el-descriptions-item>

                <el-descriptions-item label="命令执行数">
                    {{ state.total_commands_processed }}
                </el-descriptions-item>
            </el-descriptions>

        </el-card>

    </div>
</template>

<script setup lang="ts">
import { reactive, onMounted } from 'vue'
import axios from 'axios'

interface RedisState {
    redis_version: string
    uptime_in_days: number
    connected_clients: number
    used_memory_human: string
    used_memory_peak_human: string
    total_commands_processed: number
    instantaneous_ops_per_sec: number
}

const state = reactive<RedisState>({
    redis_version: '',
    uptime_in_days: 0,
    connected_clients: 0,
    used_memory_human: '',
    used_memory_peak_human: '',
    total_commands_processed: 0,
    instantaneous_ops_per_sec: 0,
})

/**
 * 获取 Redis 状态
 */
const fetchData = async () => {
    const res = await axios.get('/api/ops/redis/summary')
    Object.assign(state, res.data.data)
}

onMounted(() => {
    fetchData()

    // 🔥 实时刷新（未来可替换 WebSocket）
    setInterval(fetchData, 5000)
})
</script>

<style scoped>
.redis-monitor {
    padding: 20px;
}
</style>
