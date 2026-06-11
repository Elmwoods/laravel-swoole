<template>
    <el-card shadow="hover">
        <template #header>
            <span>Octane 状态</span>
        </template>

        <el-descriptions :column="1">
            <el-descriptions-item label="Workers">
                {{ state.workers }}
            </el-descriptions-item>

            <el-descriptions-item label="Task Workers">
                {{ state.task_workers }}
            </el-descriptions-item>

            <el-descriptions-item label="状态">
                <el-tag type="success">
                    {{ state.status }}
                </el-tag>
            </el-descriptions-item>
        </el-descriptions>
    </el-card>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { getOctaneStatus } from '../../api/octane.ts'

const state = ref({
    workers: 0,
    task_workers: 0,
    status: '-',
})

const loadData = async () => {
    const res = await getOctaneStatus()
    console.log("================")
    console.log(res)
    console.log("================")
    state.value = res.data.data
}

onMounted(loadData)
</script>
