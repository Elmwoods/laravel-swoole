<template>
    <div class="workload" v-loading="loading">
        <div class="toolbar">
            <span class="generated">最近更新：{{ data?.generated_at || '-' }}</span>
            <el-radio-group v-model="days" @change="load">
                <el-radio-button :value="7">7 天</el-radio-button>
                <el-radio-button :value="30">30 天</el-radio-button>
                <el-radio-button :value="90">90 天</el-radio-button>
            </el-radio-group>
            <el-button :loading="loading" @click="load">刷新</el-button>
        </div>

        <el-card shadow="never">
            <template #header>值班绩效（近 {{ data?.window_days ?? days }} 天，按处理人）</template>
            <el-table :data="data?.people || []" border stripe empty-text="暂无处理记录">
                <el-table-column label="处理人" prop="person" min-width="140" />
                <el-table-column label="确认数" prop="acknowledged_count" width="100" />
                <el-table-column label="平均确认时长" width="150">
                    <template #default="{ row }">{{ fmt(row.avg_ack_seconds) }}</template>
                </el-table-column>
                <el-table-column label="恢复数" prop="resolved_count" width="100" />
                <el-table-column label="平均恢复时长" width="150">
                    <template #default="{ row }">{{ fmt(row.avg_resolve_seconds) }}</template>
                </el-table-column>
                <el-table-column label="被指派数" prop="assigned_count" width="110" />
            </el-table>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getAlertWorkload, type WorkloadResult } from '@/api/opsStage4'

const loading = ref(false)
const days = ref(30)
const data = ref<WorkloadResult | null>(null)

const fmt = (s: number): string => {
    if (!s) return '—'
    if (s < 60) return `${s} 秒`
    if (s < 3600) return `${Math.round(s / 60)} 分`
    return `${Math.round(s / 360) / 10} 小时`
}

const load = async () => {
    loading.value = true
    try {
        const res = await getAlertWorkload(days.value)
        data.value = res.data.data
    } catch {
        ElMessage.error('值班绩效加载失败')
    } finally {
        loading.value = false
    }
}

onMounted(load)
</script>

<style scoped>
.workload {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.toolbar {
    align-items: center;
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.generated {
    color: #94a3b8;
    font-size: 13px;
    margin-right: auto;
}
</style>
