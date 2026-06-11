<template>

    <el-card>

        <template #header>
            Docker Containers
        </template>

        <el-table
            :data="containers"
            stripe
            size="small"
        >
            <el-table-column
                prop="name"
                label="Container"
            />

            <el-table-column
                prop="image"
                label="Image"
            />

            <el-table-column
                prop="status"
                label="Status"
            />

            <el-table-column
                prop="ports"
                label="Ports"
            />

        </el-table>

    </el-card>

</template>

<script setup lang="ts">
import {ref,onMounted} from 'vue'
import {getContainers} from '../api/docker'

const containers = ref([])

const load = async () => {

    const res = await getContainers()

    containers.value = res.data.data
}

onMounted(() => {

    load()

    setInterval(load,10000)

})
</script>
