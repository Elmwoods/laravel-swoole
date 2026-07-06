<template>
    <div class="redis-monitor">

        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <span>Redis 实时监控</span>

                    <el-button :icon="Refresh" :loading="loading" @click="fetchData">
                        刷新
                    </el-button>
                </div>
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

                <el-descriptions-item label="内存碎片率">
                    {{ state.mem_fragmentation_ratio }}
                </el-descriptions-item>
            </el-descriptions>

            <el-divider />

            <el-descriptions title="连接与持久化" :column="2" border>
                <el-descriptions-item label="角色">
                    {{ state.role || '-' }}
                </el-descriptions-item>

                <el-descriptions-item label="阻塞客户端">
                    {{ state.blocked_clients }}
                </el-descriptions-item>

                <el-descriptions-item label="拒绝连接">
                    {{ state.rejected_connections }}
                </el-descriptions-item>

                <el-descriptions-item label="RDB 状态">
                    <el-tag :type="state.rdb_last_bgsave_status === 'ok' ? 'success' : 'warning'">
                        {{ state.rdb_last_bgsave_status || '-' }}
                    </el-tag>
                </el-descriptions-item>
            </el-descriptions>

        </el-card>

    </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import { getRedisSummary, type RedisSummary } from '@/api/opsCenter'

const loading = ref(false)
let timer: number | null = null

const state = reactive<RedisSummary>({
    redis_version: '',
    redis_mode: null,
    role: null,
    uptime_in_seconds: 0,
    uptime_in_days: 0,
    connected_clients: 0,
    blocked_clients: 0,
    rejected_connections: 0,
    used_memory: 0,
    used_memory_human: '',
    used_memory_rss_human: '',
    used_memory_peak_human: '',
    mem_fragmentation_ratio: 0,
    total_commands_processed: 0,
    instantaneous_ops_per_sec: 0,
    total_connections_received: 0,
    keyspace_hits: 0,
    keyspace_misses: 0,
    used_cpu_user: 0,
    used_cpu_sys: 0,
    rdb_last_bgsave_status: null,
    aof_enabled: 0,
})

/**
 * 获取 Redis 实时摘要。
 */
const fetchData = async () => {
    loading.value = true

    try {
        const res = await getRedisSummary()
        Object.assign(state, res.data.data)
    } catch {
        ElMessage.error('Redis 状态获取失败')
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
.redis-monitor {
    padding: 0;
}

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}
</style>
