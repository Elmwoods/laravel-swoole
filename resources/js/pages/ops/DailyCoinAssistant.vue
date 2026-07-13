<template>
    <section class="coin-page">
        <el-row :gutter="16">
            <el-col :xs="24" :lg="9">
                <el-card shadow="never" class="status-card">
                    <template #header>
                        <div class="card-header">
                            <span>今日状态</span>
                            <el-tag :type="statusTag">{{ statusText }}</el-tag>
                        </div>
                    </template>

                    <div class="today-main">
                        <div class="today-date">{{ summary?.today.date || '-' }}</div>
                        <div class="coin-count">{{ summary?.today.coins ?? '-' }}</div>
                        <div class="coin-label">金币</div>
                    </div>

                    <el-alert
                        :closable="false"
                        :type="summary?.reminder.due ? 'warning' : 'success'"
                        show-icon
                    >
                        {{ summary?.reminder.due ? '今日还未记录提醒' : '今日提醒状态已同步' }}
                    </el-alert>

                    <div class="actions">
                        <el-button :loading="loading" @click="load">刷新</el-button>
                        <el-button
                            :disabled="summary?.reminder.sent_today"
                            :loading="actionLoading"
                            type="primary"
                            @click="markReminder"
                        >
                            标记提醒
                        </el-button>
                    </div>
                </el-card>
            </el-col>

            <el-col :xs="24" :lg="15">
                <el-card shadow="never">
                    <template #header>
                        <div class="card-header">
                            <span>人工确认</span>
                            <el-tag effect="plain" type="info">Manual</el-tag>
                        </div>
                    </template>

                    <el-form :model="form" label-position="top" class="confirm-form">
                        <el-form-item label="领取结果">
                            <el-radio-group v-model="form.status">
                                <el-radio-button label="claimed">已领取</el-radio-button>
                                <el-radio-button label="failed">失败</el-radio-button>
                                <el-radio-button label="skipped">跳过</el-radio-button>
                            </el-radio-group>
                        </el-form-item>

                        <el-form-item label="金币数量">
                            <el-input-number
                                v-model="form.coins"
                                :disabled="form.status !== 'claimed'"
                                :max="1000000"
                                :min="0"
                            />
                        </el-form-item>

                        <el-form-item label="备注">
                            <el-input v-model="form.note" maxlength="255" show-word-limit />
                        </el-form-item>

                        <el-button :loading="actionLoading" type="primary" @click="confirm">
                            保存确认
                        </el-button>
                    </el-form>
                </el-card>
            </el-col>
        </el-row>

        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <span>安全自动化</span>
                    <el-tag :type="summary?.safety.private_token_automation_allowed ? 'danger' : 'success'">
                        {{ summary?.safety.private_token_automation_allowed ? '允许私有 Token' : '拒绝私有 Token' }}
                    </el-tag>
                </div>
            </template>

            <div class="automation-row">
                <el-button :loading="actionLoading" @click="requestManualAutomation">
                    请求手动提醒模式
                </el-button>
                <el-button :loading="actionLoading" type="danger" plain @click="requestPrivateTokenAutomation">
                    测试私有 Token 拒绝
                </el-button>
            </div>
        </el-card>

        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <span>历史记录</span>
                    <span class="checked-at">{{ summary?.checked_at || '-' }}</span>
                </div>
            </template>

            <el-table :data="summary?.history || []" border>
                <el-table-column label="日期" prop="date" width="140" />
                <el-table-column label="状态" width="110">
                    <template #default="{ row }">
                        <el-tag :type="tagFor(row.status)">{{ textFor(row.status) }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="金币" prop="coins" width="120" />
                <el-table-column label="备注" prop="note" min-width="180" />
                <el-table-column label="更新时间" prop="updated_at" min-width="170" />
            </el-table>
        </el-card>
    </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
    confirmDailyCoin,
    getDailyCoinSummary,
    markDailyCoinReminder,
    requestDailyCoinAutomation,
    type DailyCoinSummary,
} from '@/api/dailyCoinAssistant'

const summary = ref<DailyCoinSummary | null>(null)
const loading = ref(false)
const actionLoading = ref(false)
const form = reactive({
    status: 'claimed' as 'claimed' | 'failed' | 'skipped',
    coins: 0,
    note: '',
})

const statusText = computed(() => textFor(summary.value?.today.status || 'pending'))
const statusTag = computed(() => tagFor(summary.value?.today.status || 'pending'))

const textFor = (status: string) => ({
    pending: '待处理',
    claimed: '已领取',
    failed: '失败',
    skipped: '跳过',
}[status] || status)

const tagFor = (status: string) => ({
    pending: 'warning',
    claimed: 'success',
    failed: 'danger',
    skipped: 'info',
}[status] || 'info')

const load = async () => {
    loading.value = true

    try {
        const res = await getDailyCoinSummary()
        summary.value = res.data.data
        form.status = summary.value.today.status === 'pending' ? 'claimed' : summary.value.today.status
        form.coins = summary.value.today.coins ?? 0
        form.note = summary.value.today.note || ''
    } finally {
        loading.value = false
    }
}

const markReminder = async () => {
    actionLoading.value = true

    try {
        const res = await markDailyCoinReminder()
        summary.value = res.data.data
        ElMessage.success('提醒状态已记录')
    } finally {
        actionLoading.value = false
    }
}

const confirm = async () => {
    actionLoading.value = true

    try {
        const res = await confirmDailyCoin({
            status: form.status,
            coins: form.status === 'claimed' ? form.coins : null,
            note: form.note || null,
        })
        summary.value = res.data.data
        ElMessage.success('领取状态已保存')
    } finally {
        actionLoading.value = false
    }
}

const requestManualAutomation = async () => {
    actionLoading.value = true

    try {
        await requestDailyCoinAutomation({ mode: 'manual_reminder' })
        ElMessage.success('已接受手动提醒模式')
    } finally {
        actionLoading.value = false
    }
}

const requestPrivateTokenAutomation = async () => {
    actionLoading.value = true

    try {
        await requestDailyCoinAutomation({ mode: 'private_token', token: 'unsafe-token' })
    } catch (error: any) {
        ElMessage.warning(error?.response?.data?.message || '已拒绝不安全自动化')
    } finally {
        actionLoading.value = false
    }
}

onMounted(load)
</script>

<style scoped>
.coin-page {
    display: grid;
    gap: 16px;
}

.status-card,
.coin-page :deep(.el-card) {
    border-radius: 8px;
}

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.today-main {
    padding: 10px 0 18px;
    text-align: center;
}

.today-date {
    color: #64748b;
    font-size: 14px;
}

.coin-count {
    color: #111827;
    font-size: 42px;
    font-weight: 800;
    line-height: 1.2;
    margin-top: 10px;
}

.coin-label,
.checked-at {
    color: #64748b;
    font-size: 13px;
}

.actions,
.automation-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
}

.confirm-form {
    max-width: 520px;
}
</style>
