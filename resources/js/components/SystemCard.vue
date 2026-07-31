<template>
    <!-- 系统概览卡片：loading 时展示骨架遮罩，头部提供“重试”按钮重新拉取数据 -->
    <el-card v-loading="loading" shadow="never" class="system-card">
        <template #header>
            <div class="card-header">
                <span>系统概览</span>
                <!-- 点击重新触发 init()，用于接口失败后手动重载 -->
                <el-button size="small" text @click="init">重试</el-button>
            </div>
        </template>

        <!-- 仅当存在错误信息时展示告警条（接口异常提示） -->
        <el-alert
            v-if="errorMessage"
            :title="errorMessage"
            type="warning"
            show-icon
            :closable="false"
            class="state-alert"
        />

        <!-- 指标行：左右两列分别展示 1 分钟负载与内存使用率 -->
        <el-row :gutter="20" class="metric-row">
            <!-- 系统 1 分钟平均负载（Load 1m） -->
            <el-col :xs="24" :sm="12">
                <el-statistic
                    title="Load 1m"
                    :value="cpu"
                />
            </el-col>

            <!-- 内存使用率百分比 -->
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

// 1 分钟平均负载（沿用 cpu 命名，实际展示的是 Load 1m）
const cpu = ref(0)
// 内存使用率百分比
const memory = ref(0)
// 是否处于加载中，用于 el-card 的 v-loading 遮罩
const loading = ref(false)
// 接口错误提示文案，为空时不展示告警条
const errorMessage = ref('')

// Echo 频道句柄，卸载时用于退订，避免重复监听与内存泄漏
let channel: any = null

/**
 * 初始化一次数据（避免空白）
 */
const init = async () => {
    // 进入加载态并清空历史错误，保证重试时界面干净
    loading.value = true
    errorMessage.value = ''

    try {
        // 首屏拉取一次系统概览，后续增量由 WebSocket 推送
        const res = await axios.get('/api/ops/system/summary')
        const data = res.data.data

        // 负载保留两位小数
        cpu.value = Number(Number(data.cpu ?? 0).toFixed(2))

        // 内存使用率 =（总量 - 可用）/ 总量；total 为 0 时兜底为 0 避免除零
        const total = Number(data.memory?.total ?? 0)
        const available = Number(data.memory?.available ?? 0)
        memory.value = total > 0
            ? Number((((total - available) / total) * 100).toFixed(2))
            : 0
    } catch (e) {
        // 接口异常时展示统一提示，交由用户点击“重试”
        errorMessage.value = '系统概览加载失败，请稍后重试。'
    } finally {
        // 无论成功失败都退出加载态
        loading.value = false
    }
}

/**
 * WebSocket 实时更新
 */
const startEcho = () => {
    // 已订阅则直接返回，避免重复监听
    if (channel) return

    // 订阅系统指标频道，收到 metrics.updated 广播即实时刷新卡片数值
    channel = echo.channel('ops.system.metrics')
        .listen('.metrics.updated', (e: any) => {
            const data = e?.data

            // 空负载直接忽略
            if (!data) return
            cpu.value = Number(Number(data.cpu ?? 0).toFixed(2))

            // 实时内存使用率，这里取整数（与首屏两位小数略有差异，属推送场景可接受）
            memory.value =
                Math.round(
                    ((data.memory.total - data.memory.available)
                        / data.memory.total) * 100
                )
        })
}

// 挂载时先拉一次首屏数据，再开启实时订阅
onMounted(() => {
    init()
    startEcho()
})

// 卸载时退订频道并置空句柄，防止内存泄漏与重复回调
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
