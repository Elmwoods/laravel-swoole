<template>
    <el-card class="stat-card">
        <div class="title">{{ title }}</div>
        <div class="value">{{ value }}</div>
        <div class="desc">{{ desc }}</div>
    </el-card>
</template>

<script setup>
defineProps({
    title: String,
    value: [String, Number],
    desc: String
})
</script>

<style scoped>
.stat-card {
    border-radius: 12px;
}

.title {
    font-size: 14px;
    color: #888;
}

.value {
    font-size: 22px;
    font-weight: bold;
    margin-top: 8px;
}

.desc {
    font-size: 12px;
    color: #aaa;
    margin-top: 4px;
}
</style>
