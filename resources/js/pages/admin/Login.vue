<template>
    <div class="login-page">
        <section class="login-panel">
            <div>
                <div class="brand-mark">OC</div>
                <h1>Ops Center</h1>
                <p>后台安全登录</p>
            </div>

            <el-form
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
        </section>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { useAdminAuthStore } from '@/stores/adminAuth'

const route = useRoute()
const router = useRouter()
const auth = useAdminAuthStore()
const formRef = ref<FormInstance>()
const loading = ref(false)
const form = reactive({
    email: '',
    password: '',
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

const submit = async () => {
    if (loading.value) return

    await formRef.value?.validate()
    loading.value = true

    try {
        await auth.login(form)
        form.password = ''
        const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/admin/ops'
        await router.replace(redirect)
    } catch (error: any) {
        form.password = ''
        ElMessage.error(error?.response?.data?.message || '登录失败')
    } finally {
        loading.value = false
    }
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
</style>
