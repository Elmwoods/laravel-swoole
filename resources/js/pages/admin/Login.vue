<template>
    <div class="login-page">
        <section class="login-panel">
            <div>
                <div class="brand-mark">OC</div>
                <h1>Ops Center</h1>
                <p>{{ subtitle }}</p>
            </div>

            <el-form
                v-if="step === 'password'"
                ref="formRef"
                :model="form"
                :rules="rules"
                label-position="top"
                @submit.prevent="submit"
            >
                <el-form-item label="邮箱" prop="email">
                    <el-input v-model="form.email" autocomplete="username" />
                </el-form-item>

                <el-form-item label="密码" prop="password">
                    <el-input
                        v-model="form.password"
                        autocomplete="current-password"
                        type="password"
                    />
                </el-form-item>

                <el-button :loading="loading" native-type="submit" type="primary">
                    登录
                </el-button>
            </el-form>

            <div v-else-if="step === 'setup'" class="two-factor-step">
                <div class="qr-wrap">
                    <img v-if="qrDataUrl" :src="qrDataUrl" alt="TOTP QR Code" />
                </div>

                <el-alert :closable="false" show-icon type="warning">
                    所有后台管理员必须绑定认证器应用。请扫描二维码或手动输入密钥后填写 6 位验证码。
                </el-alert>

                <div class="manual-secret">
                    <span>手动密钥</span>
                    <code>{{ setup?.secret }}</code>
                </div>

                <el-form :model="twoFactorForm" label-position="top" @submit.prevent="confirmSetup">
                    <el-form-item label="验证码">
                        <el-input
                            v-model="twoFactorForm.code"
                            autocomplete="one-time-code"
                            maxlength="6"
                            inputmode="numeric"
                        />
                    </el-form-item>

                    <el-button :loading="loading" native-type="submit" type="primary">
                        完成绑定
                    </el-button>
                </el-form>
            </div>

            <div v-else-if="step === 'challenge'" class="two-factor-step">
                <el-form :model="twoFactorForm" label-position="top" @submit.prevent="submitChallenge">
                    <el-form-item :label="useRecovery ? '恢复码' : '验证码'">
                        <el-input
                            v-if="useRecovery"
                            v-model="twoFactorForm.recovery_code"
                            autocomplete="one-time-code"
                        />
                        <el-input
                            v-else
                            v-model="twoFactorForm.code"
                            autocomplete="one-time-code"
                            maxlength="6"
                            inputmode="numeric"
                        />
                    </el-form-item>

                    <el-checkbox v-model="trustDevice" class="trust-device">
                        记住此设备 30 天（此后在本设备登录跳过二次验证）
                    </el-checkbox>

                    <el-button :loading="loading" native-type="submit" type="primary">
                        验证并登录
                    </el-button>
                    <el-button :disabled="loading" text type="primary" @click="toggleRecovery">
                        {{ useRecovery ? '使用验证码' : '使用恢复码' }}
                    </el-button>
                </el-form>
            </div>

            <div v-else class="recovery-step">
                <el-alert :closable="false" show-icon type="success">
                    2FA 已启用。恢复码只显示一次，请保存到安全位置。
                </el-alert>

                <div class="recovery-grid">
                    <code v-for="code in recoveryCodes" :key="code">{{ code }}</code>
                </div>

                <el-button type="primary" @click="finishLogin">
                    我已保存，进入后台
                </el-button>
            </div>
        </section>
    </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import QRCode from 'qrcode'
import { useAdminAuthStore } from '@/stores/adminAuth'
import type { AdminTwoFactorSetup } from '@/api/adminSecurity'

const route = useRoute()
const router = useRouter()
const auth = useAdminAuthStore()
const formRef = ref<FormInstance>()
const loading = ref(false)
const step = ref<'password' | 'setup' | 'challenge' | 'recovery'>('password')
const setup = ref<AdminTwoFactorSetup | null>(null)
const qrDataUrl = ref('')
const useRecovery = ref(false)
const trustDevice = ref(false)
const recoveryCodes = ref<string[]>([])
const form = reactive({
    email: '',
    password: '',
})
const twoFactorForm = reactive({
    code: '',
    recovery_code: '',
})
const rules: FormRules = {
    email: [
        { required: true, message: '请输入邮箱', trigger: 'blur' },
        { type: 'email', message: '邮箱格式不正确', trigger: 'blur' },
    ],
    password: [
        { required: true, message: '请输入密码', trigger: 'blur' },
        { min: 8, message: '密码至少 8 位', trigger: 'blur' },
    ],
}

const subtitle = computed(() => {
    if (step.value === 'setup') return '绑定二次验证'
    if (step.value === 'challenge') return '二次验证'
    if (step.value === 'recovery') return '保存恢复码'

    return '后台安全登录'
})

const submit = async () => {
    if (loading.value) return

    await formRef.value?.validate()
    loading.value = true

    try {
        const result = await auth.login(form)
        form.password = ''

        if (result.requires_two_factor_setup && result.setup) {
            setup.value = result.setup
            qrDataUrl.value = await QRCode.toDataURL(result.setup.otpauth_uri, {
                margin: 1,
                width: 184,
            })
            step.value = 'setup'

            return
        }

        if (result.requires_two_factor) {
            step.value = 'challenge'

            return
        }

        await finishLogin()
    } catch (error: any) {
        form.password = ''
        ElMessage.error(error?.response?.data?.message || '登录失败')
    } finally {
        loading.value = false
    }
}

const confirmSetup = async () => {
    if (loading.value) return

    loading.value = true

    try {
        const result = await auth.confirmTwoFactor({ code: twoFactorForm.code })
        recoveryCodes.value = result.recovery_codes
        twoFactorForm.code = ''
        step.value = 'recovery'
    } catch (error: any) {
        ElMessage.error(error?.response?.data?.message || '二次验证绑定失败')
    } finally {
        loading.value = false
    }
}

const submitChallenge = async () => {
    if (loading.value) return

    loading.value = true

    try {
        await auth.challengeTwoFactor(useRecovery.value
            ? { recovery_code: twoFactorForm.recovery_code, trust_device: trustDevice.value }
            : { code: twoFactorForm.code, trust_device: trustDevice.value })
        twoFactorForm.code = ''
        twoFactorForm.recovery_code = ''
        await finishLogin()
    } catch (error: any) {
        ElMessage.error(error?.response?.data?.message || '二次验证失败')
    } finally {
        loading.value = false
    }
}

const toggleRecovery = () => {
    useRecovery.value = !useRecovery.value
    twoFactorForm.code = ''
    twoFactorForm.recovery_code = ''
}

const finishLogin = async () => {
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/admin/ops'
    await router.replace(redirect)
}
</script>

<style scoped>
.login-page {
    align-items: center;
    background: #eef2f7;
    display: flex;
    min-height: 100vh;
    padding: 24px;
}

.login-panel {
    background: #fff;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.12);
    margin: 0 auto;
    max-width: 420px;
    padding: 34px;
    width: 100%;
}

.brand-mark {
    align-items: center;
    background: #22c55e;
    border-radius: 8px;
    color: #052e16;
    display: flex;
    font-weight: 800;
    height: 42px;
    justify-content: center;
    margin-bottom: 16px;
    width: 42px;
}

h1 {
    color: #111827;
    font-size: 28px;
    margin: 0;
}

p {
    color: #64748b;
    margin: 8px 0 28px;
}

.el-button {
    width: 100%;
}

.two-factor-step,
.recovery-step {
    display: grid;
    gap: 16px;
}

.trust-device {
    color: #475569;
    height: auto;
    line-height: 1.4;
    white-space: normal;
}

.qr-wrap {
    align-items: center;
    background: #f8fafc;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    display: flex;
    justify-content: center;
    min-height: 206px;
}

.qr-wrap img {
    display: block;
    height: 184px;
    width: 184px;
}

.manual-secret {
    display: grid;
    gap: 6px;
}

.manual-secret span {
    color: #64748b;
    font-size: 13px;
}

code {
    background: #f8fafc;
    border: 1px solid #dbe3ef;
    border-radius: 6px;
    color: #334155;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    padding: 8px;
    word-break: break-all;
}

.recovery-grid {
    display: grid;
    gap: 8px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
</style>
