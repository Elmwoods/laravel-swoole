<template>
    <el-dialog
        v-model="visible"
        title="Container Logs (Live)"
        width="80%"
        :before-close="handleClose"
    >
        <div class="log-box" ref="logBox">
            <pre>{{ logs }}</pre>
        </div>

        <template #footer>
            <div class="dialog-footer">
                <el-button @click="clearLogs" size="small">Clear</el-button>
                <el-button type="primary" @click="handleClose" size="small">
                    Close
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
let channel = null
let currentContainerId = null
const logBox = ref(null)

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
    scrollToBottom()
}

// 清空日志
const clearLogs = () => {
    logs.value = ''
}

const open = (containerId) => {
    visible.value = true
    logs.value = ''
    currentContainerId = containerId

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
                console.error('Echo error:', err)
                ElMessage.error('WebSocket connection error')
            })
    } catch (err) {
        console.error(err)
        ElMessage.error('Failed to subscribe to logs')
    }
}

// 备选拉取模式（如果后端广播仅通知）
const fetchLatestLogs = async (containerId) => {
    try {
        const res = await getDockerLogs(containerId)
        if (res.data.data) {
            appendLog(res.data.data)
        }
    } catch (error) {
        console.error('Failed to fetch logs:', error)
    }
}

const handleClose = () => {
    visible.value = false
    if (channel) {
        echo.leaveChannel(channel)
        channel = null
    }
    currentContainerId = null
}

defineExpose({
    open,
    close: handleClose,
    clearLogs
})
</script>

<style scoped>
.log-box {
    height: 600px;
    overflow-y: auto;
    background: #0d0d0d;
    color: #00ff00;
    padding: 15px;
    border-radius: 6px;
    font-size: 12px;
    font-family: monospace;
    white-space: pre-wrap;
}
</style>
