<template>
    <el-dialog
        v-model="visible"
        title="容器日志"
        width="min(920px, 92vw)"
        :before-close="handleClose"
    >
        <div class="log-toolbar">
            <div class="log-meta">
                <div class="log-title">{{ currentContainerId || '-' }}</div>
                <div class="log-desc">WebSocket 仅接收小体积更新，完整内容通过 HTTP tail 拉取。</div>
            </div>
            <el-button size="small" :loading="loading" @click="reloadLogs">
                重新拉取
            </el-button>
        </div>

        <el-alert
            v-if="errorMessage"
            :title="errorMessage"
            type="warning"
            show-icon
            :closable="false"
            class="state-alert"
        />

        <div class="log-box" ref="logBox" v-loading="loading">
            <el-empty v-if="!loading && !logs" description="暂无容器日志" />
            <pre v-else>{{ logs }}</pre>
        </div>

        <template #footer>
            <div class="dialog-footer">
                <el-button @click="clearLogs" size="small">清空</el-button>
                <el-button type="primary" @click="handleClose" size="small">
                    关闭
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup>
import { ref, nextTick } from 'vue'
import { ElMessage } from 'element-plus'
import echo from '@/utils/echo'
import { getDockerLogs } from '@/api/opsStage2'

const visible = ref(false)
const logs = ref('')
const loading = ref(false)
const errorMessage = ref('')
let channel = null
const currentContainerId = ref(null)
const logBox = ref(null)
const MAX_LOG_CHARS = 120000

// 自动滚动到底部
const scrollToBottom = async () => {
    await nextTick()
    if (logBox.value) {
        logBox.value.scrollTop = logBox.value.scrollHeight
    }
}

// 追加日志并自动滚动
const appendLog = (text) => {
    if (!text) return
    logs.value += text + '\n'
    if (logs.value.length > MAX_LOG_CHARS) {
        logs.value = logs.value.slice(-MAX_LOG_CHARS)
    }
    scrollToBottom()
}

// 清空日志
const clearLogs = () => {
    logs.value = ''
}

const open = (containerId) => {
    visible.value = true
    logs.value = ''
    errorMessage.value = ''
    currentContainerId.value = containerId

    if (channel) {
        echo.leaveChannel(channel)
    }

    channel = `docker.logs.${containerId}`

    fetchLatestLogs(containerId)

    try {
        echo.channel(channel)
            .listen('.log.updated', (event) => {
                // 后端只广播安全大小的日志预览，完整日志通过 HTTP 拉取。
                if (event.log !== undefined && event.truncated !== true) {
                    appendLog(event.log)
                }

                if (event.truncated === true || event.log === undefined) {
                    fetchLatestLogs(containerId)
                }
            })
            .error((err) => {
                if (import.meta.env.DEV) {
                    console.error('Docker log websocket failed', {
                        message: err?.message,
                        container: containerId,
                    })
                }
                ElMessage.error('容器日志实时连接失败，已保留 HTTP 拉取。')
            })
    } catch (err) {
        if (import.meta.env.DEV) {
            console.error('Docker log subscription failed', {
                message: err?.message,
                container: containerId,
            })
        }
        ElMessage.error('容器日志订阅失败，已保留 HTTP 拉取。')
    }
}

// 备选拉取模式（如果后端广播仅通知）
const fetchLatestLogs = async (containerId) => {
    loading.value = true
    errorMessage.value = ''

    try {
        const res = await getDockerLogs(containerId)
        if (res.data.data) {
            logs.value = String(res.data.data).slice(-MAX_LOG_CHARS)
            scrollToBottom()
        }
    } catch (error) {
        errorMessage.value = '容器日志拉取失败，请稍后重试。'
        if (import.meta.env.DEV) {
            console.error('Docker log fetch failed', {
                message: error?.message,
                status: error?.response?.status,
                container: containerId,
            })
        }
    } finally {
        loading.value = false
    }
}

const reloadLogs = () => {
    if (!currentContainerId.value) return
    fetchLatestLogs(currentContainerId.value)
}

const handleClose = () => {
    visible.value = false
    if (channel) {
        echo.leaveChannel(channel)
        channel = null
    }
    currentContainerId.value = null
    errorMessage.value = ''
}

defineExpose({
    open,
    close: handleClose,
    clearLogs
})
</script>

<style scoped>
.log-box {
    border: 1px solid #1f2937;
    height: min(600px, 60vh);
    min-height: 320px;
    overflow-y: auto;
    background: #101827;
    color: #d1fae5;
    padding: 15px;
    border-radius: 8px;
    font-size: 12px;
    font-family: monospace;
    white-space: pre-wrap;
}

.log-box pre {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
}

.log-toolbar {
    align-items: flex-start;
    display: flex;
    gap: 16px;
    justify-content: space-between;
    margin-bottom: 12px;
}

.log-title {
    color: #111827;
    font-size: 13px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.log-desc {
    color: #6b7280;
    font-size: 12px;
    margin-top: 4px;
}

.state-alert {
    margin-bottom: 12px;
}

@media (max-width: 640px) {
    .log-toolbar {
        align-items: stretch;
        flex-direction: column;
    }
}
</style>
