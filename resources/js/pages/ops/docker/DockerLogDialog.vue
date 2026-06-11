<template>

    <el-dialog
        v-model="visible"
        title="Container Logs"
        width="80%"
    >

        <div class="log-box">

            <pre>{{ logs }}</pre>

        </div>

    </el-dialog>

</template>

<script setup>

import { ref } from 'vue'

/**
 * 是否显示
 */
const visible = ref(false)

/**
 * 当前日志
 */
const logs = ref('')

/**
 * 当前监听频道
 */
let channel = null

/**
 * 打开日志窗口
 */
const open = (containerId) => {

    visible.value = true

    logs.value = ''

    /**
     * 避免重复监听
     */
    if (channel) {

        window.Echo.leave(channel)
    }

    channel =
        `docker.logs.${containerId}`

    /**
     * WebSocket监听
     */
    window.Echo
        .channel(channel)
        .listen(
            '.log.updated',
            (event) => {

                logs.value = event.log
            }
        )
}

/**
 * 关闭
 */
const close = () => {

    visible.value = false

    if (channel) {

        window.Echo.leave(channel)

        channel = null
    }
}

defineExpose({
    open,
    close
})

</script>

<style scoped>

.log-box {

    height: 600px;

    overflow-y: auto;

    background: #111;

    color: #00ff00;

    padding: 15px;

    border-radius: 5px;

    font-size: 12px;

    font-family: monospace;
}

</style>
