<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from '@/utils/axios'

const active = ref('laravel')

const logs = ref<any[]>([])

const load = async () => {
    const { data } = await axios.get(
        `/api/ops/logs/${active.value}`
    )

    logs.value = data
}

onMounted(load)
</script>

<template>

    <el-card>

        <el-tabs
            v-model="active"
            @tab-change="load"
        >

            <el-tab-pane
                label="Laravel"
                name="laravel"
            />

            <el-tab-pane
                label="Octane"
                name="octane"
            />

            <el-tab-pane
                label="Redis SlowLog"
                name="redis"
            />

            <el-tab-pane
                label="Docker"
                name="docker"
            />

        </el-tabs>

        <el-scrollbar
            height="700px"
        >
        <pre>
{{ logs }}
        </pre>
        </el-scrollbar>

    </el-card>

</template>
