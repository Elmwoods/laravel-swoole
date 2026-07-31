<template>
    <!-- IP 准入管理页：上半部分是全局准入策略，下半部分是具体 IP 规则表 -->
    <div class="security-page">
        <!-- 准入策略卡片：开关 + 名单模式 + 自动封禁配置，头部显示当前访问者 IP 便于自查 -->
        <el-card v-loading="loading" shadow="never" class="policy-card">
            <template #header>
                <div class="card-header">
                    <span>准入策略</span>
                    <span class="client-ip">当前访问 IP：{{ clientIp || '—' }}</span>
                </div>
            </template>

            <el-form label-position="top" class="policy-form">
                <!-- 总开关：关闭时放行全部来源，作为不改变现有登录行为的安全默认 -->
                <el-form-item label="启用 IP 准入">
                    <el-switch v-model="settings.ip_access_enabled" />
                    <span class="hint">关闭时放行全部来源（不改变现有登录行为）。</span>
                </el-form-item>
                <!-- 名单模式：黑名单（拒绝命中）或白名单（仅放行命中） -->
                <el-form-item label="名单模式">
                    <el-select v-model="settings.ip_access_mode" style="width: 220px">
                        <el-option label="黑名单（拒绝命中的 IP）" value="blocklist" />
                        <el-option label="白名单（仅放行命中的 IP）" value="allowlist" />
                    </el-select>
                </el-form-item>
                <!-- fail-open 提示：白名单但无启用 allow 规则时会放行全部，提醒用户先加网段以防自锁 -->
                <el-alert
                    v-if="showFailOpenHint"
                    class="policy-alert"
                    type="warning"
                    show-icon
                    :closable="false"
                    title="白名单模式下没有任何启用的 allow 规则时，为避免锁死所有人将放行全部来源（fail-open）。请先添加办公网/VPN 出口网段。"
                />
                <el-divider content-position="left">自动封禁</el-divider>
                <!-- 自动封禁开关：提示文案里用后端返回的阈值/窗口/时长动态拼接说明 -->
                <el-form-item label="启用自动封禁">
                    <el-switch v-model="settings.auto_ban_enabled" />
                    <span class="hint">
                        失败登录暴增的来源 IP 自动临时封禁（近 {{ autoBan.window_minutes }} 分钟 ≥
                        {{ autoBan.threshold }} 次 → 封 {{ autoBan.ban_minutes }} 分钟，到期自动解封）。
                    </span>
                </el-form-item>
                <!-- 仅当开启自动封禁时提示反代场景需先配置可信代理，否则可能误封代理导致全员锁死 -->
                <el-alert
                    v-if="settings.auto_ban_enabled"
                    class="policy-alert"
                    type="warning"
                    show-icon
                    :closable="false"
                    title="反向代理后须先配置 OPS_TRUSTED_PROXIES，否则可能封掉代理导致所有人无法登录。阈值/窗口/时长通过环境变量调整。"
                />
                <el-form-item>
                    <el-button :loading="savingSettings" type="primary" @click="saveSettings">保存策略</el-button>
                </el-form-item>
            </el-form>
        </el-card>

        <!-- IP 规则表卡片：列出所有 allow/deny 规则，可新增/启停/删除 -->
        <el-card shadow="never">
            <template #header>
                <div class="card-header">
                    <span>IP 规则</span>
                    <el-button type="primary" @click="openCreate">新增规则</el-button>
                </div>
            </template>

            <el-table v-loading="loading" :data="rules" border empty-text="暂无规则">
                <!-- 类型列：allow 绿标签 / deny 红标签 -->
                <el-table-column label="类型" width="110">
                    <template #default="{ row }">
                        <el-tag :type="row.type === 'allow' ? 'success' : 'danger'">
                            {{ row.type === 'allow' ? '允许' : '拒绝' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="IP / CIDR" prop="cidr" />
                <!-- 来源列：区分自动封禁生成（auto）还是管理员手动添加（manual） -->
                <el-table-column label="来源" width="90">
                    <template #default="{ row }">
                        <el-tag :type="row.source === 'auto' ? 'warning' : 'info'" effect="plain">
                            {{ row.source === 'auto' ? '自动' : '手动' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="备注" prop="label">
                    <template #default="{ row }">{{ row.label || '—' }}</template>
                </el-table-column>
                <!-- 过期列：无过期时间视为「永久」规则 -->
                <el-table-column label="过期" width="180">
                    <template #default="{ row }">{{ row.expires_at || '永久' }}</template>
                </el-table-column>
                <!-- 启用列：用行级 _toggling 标志单独控制该开关 loading，避免整表刷新 -->
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

        <!-- 新增 IP 规则弹窗：填写类型 / 网段 / 备注，提交前做前端校验 -->
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
    type AutoBanSummary,
    type IpAccessSettings,
    type IpRuleType,
} from '@/api/adminIpRules'

// 页面级加载态（拉取策略+规则时整卡片 loading）
const loading = ref(false)
// 保存准入策略进行中标志
const savingSettings = ref(false)
// 新增规则提交进行中标志
const saving = ref(false)
// 新增规则弹窗显隐
const dialogVisible = ref(false)
// 当前访问者 IP，展示在策略卡片头部，方便管理员确认自己不会被误封
const clientIp = ref('')
// 规则列表；每行附加可选 _toggling 标志用于该行启停开关的独立 loading
const rules = ref<Array<AdminIpRule & { _toggling?: boolean }>>([])
// 准入策略设置（开关 + 名单模式 + 自动封禁开关），加载后从后端回填
const settings = reactive<IpAccessSettings>({
    ip_access_enabled: false,
    ip_access_mode: 'blocklist',
    auto_ban_enabled: false,
})
// 自动封禁参数（阈值/窗口/时长），只读展示，实际值由后端环境变量控制
const autoBan = reactive<AutoBanSummary>({
    threshold: 10,
    window_minutes: 10,
    ban_minutes: 60,
})

const formRef = ref<FormInstance>()
// 新增规则表单模型，默认类型为 deny（更保守，避免误加白名单放行）
const form = reactive<{ type: IpRuleType; cidr: string; label: string }>({
    type: 'deny',
    cidr: '',
    label: '',
})

// IP / CIDR 的宽松正则：允许 IPv4/IPv6 字符与可选的 /掩码，仅做前端粗校验，最终以后端为准
const cidrPattern = /^[0-9a-fA-F:.]+(\/\d{1,3})?$/

// 新增规则表单校验规则：类型必选，网段必填且需匹配 CIDR 格式
const formRules: FormRules = {
    type: [{ required: true, message: '请选择类型', trigger: 'change' }],
    cidr: [
        { required: true, message: '请输入 IP 或 CIDR 网段', trigger: 'blur' },
        { pattern: cidrPattern, message: '格式不正确（示例 10.0.0.0/8）', trigger: 'blur' },
    ],
}

// 是否显示 fail-open 警告。
// 为什么：白名单模式下若没有任何启用的 allow 规则，后端会放行全部（fail-open）以防自锁，
// 此时需在界面显式警示用户尽快补规则。
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
        settings.auto_ban_enabled = data.settings.auto_ban_enabled
        Object.assign(autoBan, data.auto_ban)
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
            auto_ban_enabled: settings.auto_ban_enabled,
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
