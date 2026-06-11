<template>
    <el-card>
        <h2>Octane 控制台</h2>

        <el-button type="primary" @click="reload">Reload</el-button>
        <el-button type="danger" @click="restart">Restart</el-button>
        <el-button @click="fetch">Refresh</el-button>

        <pre>{{ data }}</pre>
    </el-card>
</template>

<script setup lang="ts">
import axios from 'axios'
import { ref, onMounted } from 'vue'

const data = ref({})

const fetch = async () => {
    const res = await axios.get('/api/ops/octane/status')
    data.value = res.data.data
}

const reload = async () => {
    await axios.post('/api/ops/octane/reload')
    fetch()
}

const restart = async () => {
    await axios.post('/api/ops/octane/restart')
    fetch()
}

onMounted(fetch)
</script>
