<template>
    <div class="security-page">
        <el-card v-loading="loading" shadow="never" class="policy-card">
            <template #header>
                <div class="card-header">
                    <span>准入策略</span>
                    <span class="client-ip">当前访问 IP：{{ clientIp || '—' }}</span>
                </div>
            </template>

            <el-form label-position="top" class="policy-form">
                <el-form-item label="启用 IP 准入">
                    <el-switch v-model="settings.ip_access_enabled" />
                    <span class="hint">关闭时放行全部来源（不改变现有登录行为）。</span>
                </el-form-item>
                <el-form-item label="名单模式">
                    <el-select v-model="settings.ip_access_mode" style="width: 220px">
                        <el-option label="黑名单（拒绝命中的 IP）" value="blocklist" />
                        <el-option label="白名单（仅放行命中的 IP）" value="allowlist" />
                    </el-select>
                </el-form-item>
                <el-alert
                    v-if="showFailOpenHint"
                    class="policy-alert"
                    type="warning"
                    show-icon
                    :closable="false"
                    title="白名单模式下没有任何启用的 allow 规则时，为避免锁死所有人将放行全部来源（fail-open）。请先添加办公网/VPN 出口网段。"
                />
                <el-form-item>
                    <el-button :loading="savingSettings" type="primary" @click="saveSettings">保存策略</el-button>
                </el-form-item>
            </el-form>
        </el-card>

        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <span>IP 规则</span>
                    <el-button type="primary" @click="openCreate">新增规则</el-button>
                </div>
            </template>

            <el-table v-loading="loading" :data="rules" border empty-text="暂无规则">
                <el-table-column label="类型" width="110">
                    <template #default="{ row }">
                        <el-tag :type="row.type === 'allow' ? 'success' : 'danger'">
                            {{ row.type === 'allow' ? '允许' : '拒绝' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="IP / CIDR" prop="cidr" />
                <el-table-column label="备注" prop="label">
                    <template #default="{ row }">{{ row.label || '—' }}</template>
                </el-table-column>
                <el-table-column label="启用" width="90">
                    <template #default="{ row }">
                        <el-switch
                            :model-value="row.is_active"
                            :loading="row._toggling"
                            @change="(v: boolean) => toggle(row, v)"
                        />
                    </template>
                </el-table-column>
                <el-table-column label="创建时间" prop="created_at" width="180">
                    <template #default="{ row }">{{ row.created_at || '—' }}</template>
                </el-table-column>
                <el-table-column label="操作" width="100">
                    <template #default="{ row }">
                        <el-button link type="danger" @click="remove(row)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <el-dialog v-model="dialogVisible" title="新增 IP 规则" width="520px">
            <el-form ref="formRef" :model="form" :rules="formRules" label-position="top">
                <el-form-item label="类型" prop="type">
                    <el-select v-model="form.type" style="width: 100%">
                        <el-option label="允许（allow）" value="allow" />
                        <el-option label="拒绝（deny）" value="deny" />
                    </el-select>
                </el-form-item>
                <el-form-item label="IP / CIDR 网段" prop="cidr">
                    <el-input v-model="form.cidr" placeholder="如 203.0.113.0/24 或 2001:db8::/32 或单个 IP" />
                </el-form-item>
                <el-form-item label="备注" prop="label">
                    <el-input v-model="form.label" placeholder="可选，如 办公网出口" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button :disabled="saving" @click="dialogVisible = false">取消</el-button>
                <el-button :loading="saving" type="primary" @click="submit">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
    createIpRule,
    deleteIpRule,
    getIpAccess,
    toggleIpRule,
    updateIpAccessSettings,
    type AdminIpRule,
    type IpAccessSettings,
    type IpRuleType,
} from '@/api/adminIpRules'

const loading = ref(false)
const savingSettings = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const clientIp = ref('')
const rules = ref<Array<AdminIpRule & { _toggling?: boolean }>>([])
const settings = reactive<IpAccessSettings>({
    ip_access_enabled: false,
    ip_access_mode: 'blocklist',
})

const formRef = ref<FormInstance>()
const form = reactive<{ type: IpRuleType; cidr: string; label: string }>({
    type: 'deny',
    cidr: '',
    label: '',
})

const cidrPattern = /^[0-9a-fA-F:.]+(\/\d{1,3})?$/

const formRules: FormRules = {
    type: [{ required: true, message: '请选择类型', trigger: 'change' }],
    cidr: [
        { required: true, message: '请输入 IP 或 CIDR 网段', trigger: 'blur' },
        { pattern: cidrPattern, message: '格式不正确（示例 10.0.0.0/8）', trigger: 'blur' },
    ],
}

const showFailOpenHint = computed(
    () =>
        settings.ip_access_mode === 'allowlist' &&
        !rules.value.some(rule => rule.type === 'allow' && rule.is_active),
)

const load = async () => {
    loading.value = true

    try {
        const res = await getIpAccess()
        const data = res.data.data
        settings.ip_access_enabled = data.settings.ip_access_enabled
        settings.ip_access_mode = data.settings.ip_access_mode
        rules.value = data.rules
        clientIp.value = data.client_ip
    } finally {
        loading.value = false
    }
}

const saveSettings = async () => {
    if (savingSettings.value) return

    savingSettings.value = true

    try {
        await updateIpAccessSettings({
            ip_access_enabled: settings.ip_access_enabled,
            ip_access_mode: settings.ip_access_mode,
        })
        ElMessage.success('已保存策略')
        await load()
    } finally {
        savingSettings.value = false
    }
}

const openCreate = () => {
    Object.assign(form, { type: 'deny', cidr: '', label: '' })
    formRef.value?.clearValidate()
    dialogVisible.value = true
}

const submit = async () => {
    if (saving.value) return

    const valid = await formRef.value?.validate().catch(() => false)
    if (!valid) return

    saving.value = true

    try {
        await createIpRule({ type: form.type, cidr: form.cidr.trim(), label: form.label.trim() || null })
        ElMessage.success('已添加规则')
        dialogVisible.value = false
        await load()
    } finally {
        saving.value = false
    }
}

const toggle = async (row: AdminIpRule & { _toggling?: boolean }, active: boolean) => {
    row._toggling = true

    try {
        await toggleIpRule(row.id, active)
        row.is_active = active
    } finally {
        row._toggling = false
    }
}

const remove = async (row: AdminIpRule) => {
    try {
        await ElMessageBox.confirm(`确认删除规则 ${row.cidr}？`, '删除 IP 规则', {
            type: 'warning',
            confirmButtonText: '删除',
            cancelButtonText: '取消',
        })
    } catch {
        return
    }

    await deleteIpRule(row.id)
    ElMessage.success('已删除')
    await load()
}

onMounted(load)
</script>

<style scoped>
.security-page {
    display: grid;
    gap: 16px;
}

.card-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.client-ip {
    color: #64748b;
    font-size: 13px;
}

.policy-form {
    max-width: 520px;
}

.policy-alert {
    margin-bottom: 16px;
}

.hint {
    color: #64748b;
    font-size: 12px;
    margin-left: 10px;
}
</style>
