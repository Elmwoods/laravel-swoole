<template>
    <div class="disk-monitor">

        <el-card>
            <template #header>
                <span>Disk 使用率监控</span>
                <el-button style="float:right" type="primary" size="small" @click="fetchData">
                    刷新
                </el-button>
            </template>

            <el-table :data="disks" style="width: 100%">

                <el-table-column prop="filesystem" label="磁盘" />

                <el-table-column prop="mount" label="挂载点" />

                <el-table-column prop="size" label="总容量(GB)" />

                <el-table-column prop="used" label="已用(GB)" />

                <el-table-column prop="available" label="可用(GB)" />

                <el-table-column label="使用率">
                    <template #default="{ row }">
                        <el-progress
                            :percentage="row.usage"
                            :status="row.usage > 80 ? 'exception' : 'success'"
                        />
                    </template>
                </el-table-column>

            </el-table>

            <div class="time">
                更新时间：{{ timestamp }}
            </div>

        </el-card>

    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import axios from 'axios'
import echo from '@/utils/echo'

interface Disk {
    filesystem: string
    size: number
    used: number
    available: number
    usage: number
    mount: string
}

const disks = ref<Disk[]>([])
const timestamp = ref('')

const fetchData = async () => {
    const res = await axios.get('/api/ops/system/disk')
    disks.value = res.data.data
    timestamp.value = new Date().toLocaleString()
}

let channel: any = null

onMounted(() => {

    // 初始加载
    fetchData()

    // WebSocket 监听
    channel = echo.channel('ops.system.disk')
        .listen('.disk.updated', (e: any) => {
            // 关键修复：直接取数组
            const payload = e?.data || e

            disks.value = Array.isArray(payload) ? payload : []

            // timestamp 如果后端没给就不要强依赖
            timestamp.value = e?.timestamp || new Date().toLocaleString()
        })
})

onBeforeUnmount(() => {
    if (channel) {
        echo.leave('ops.system.disk')
    }
})
</script>
