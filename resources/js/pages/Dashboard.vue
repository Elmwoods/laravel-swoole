<template>
    <div class="dashboard">

        <h2>Ops Center Dashboard</h2>

        <!-- 操作按钮 -->
        <el-space style="margin-bottom: 20px">

            <el-button type="primary" @click="reload">
                Reload Octane
            </el-button>

            <el-button
                type="danger"
                @click="reload"
            >
                Reload Octane
            </el-button>

            <el-button @click="fetch">
                Refresh
            </el-button>

        </el-space>

        <!-- 高级系统信息图表 -->
        <el-row :gutter="20">

            <el-col :span="24">
                <AdvancedSystemChart />
            </el-col>

        </el-row>
        <!-- 系统信息 -->
        <el-row :gutter="20">
            <el-col :span="12">
                <SystemCard />
            </el-col>
        </el-row>
        <el-row :gutter="20">
            <el-col :span="6">
                <StatCard
                    title="CPU Load"
                    :value="data?.system?.cpu_load"
                    desc="1 minute average"
                />
            </el-col>

            <el-col :span="6">
                <StatCard
                    title="Memory"
                    :value="data?.system?.memory?.used_mb + ' MB'"
                    desc="PHP memory usage"
                />
            </el-col>

            <el-col :span="6">
                <StatCard
                    title="Redis"
                    :value="data?.redis?.connected ? 'OK' : 'DOWN'"
                    desc="Redis status"
                />
            </el-col>

            <el-col :span="6">
                <StatCard
                    title="MySQL"
                    :value="data?.mysql?.connected ? 'OK' : 'DOWN'"
                    desc="Database status"
                />
            </el-col>

        </el-row>

        <!-- Octane 状态 -->
<!--        <el-card style="margin-top: 20px">-->

<!--            <h3>Octane Status</h3>-->

<!--            <p>Running: {{ data?.octane?.running }}</p>-->
<!--            <p>PID: {{ data?.octane?.pid }}</p>-->
<!--            <p>Server: {{ data?.octane?.server }}</p>-->

<!--        </el-card>-->
        <OctaneControl
            :octane="data?.octane"
            @reload="reload"
            @stop="stop"
        />

<!--        <el-col :span="24">-->
<!--            <DockerCard />-->
<!--        </el-col>-->

        <el-row :gutter="20">

            <el-col :span="24">
                <SystemChart />
            </el-col>

        </el-row>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import StatCard from '@/components/StatCard.vue'
import OctaneCard from '../components/ops/OctaneCard.vue'
import DockerCard from '@/components/DockerCard.vue'
import SystemCard from '@/components/SystemCard.vue'
import SystemChart from '@/components/SystemChart.vue'
import AdvancedSystemChart from '@/components/AdvancedSystemChart.vue'



import {
    getDashboard,
    reloadOctane,
    stopOctane
} from '@/api/ops'
import OctaneControl from "./ops/OctaneControl.vue";

const data = ref(null)

/**
 * 获取数据
 */
const fetch = async () => {
    const res = await getDashboard()
    data.value = res.data.data
}

/**
 * reload worker
 */
const reload = async () => {
    await reloadOctane()
    await fetch()
}

/**
 * stop server
 */
const stop = async () => {
    try {
        await stopOctane()
    } catch (e) {
        console.log('Octane stopped')
    }
}

/**
 * 自动刷新（5秒）
 */
let timer = null

onMounted(() => {
    fetch()

    timer = setInterval(() => {
        fetch()
    }, 5000)
})
</script>

<style scoped>
.dashboard {
    padding: 20px;
}
</style>
