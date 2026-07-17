# Ops Center 第十一阶段：后台强制 2FA/TOTP 登录

## 范围

- 所有后台管理员强制启用 TOTP 二次验证。
- 密码验证成功后只写入 pending session，不创建完整后台登录态。
- 未绑定 2FA 的账号必须先扫描二维码或输入手动密钥完成绑定。
- 已绑定 2FA 的账号必须提交 6 位 TOTP 或一次性恢复码后才能登录。
- 超级管理员可以重置其他管理员 2FA；不能重置自己的 2FA。

## 接口

```text
POST /api/admin/auth/two-factor/confirm
POST /api/admin/auth/two-factor/challenge
POST /api/admin/users/{adminUser}/two-factor/reset
```

登录响应新增分支：

- `requires_two_factor_setup=true`：返回 `setup.secret` 和 `setup.otpauth_uri`。
- `requires_two_factor=true`：提示前端进入验证码或恢复码挑战。
- 2FA 通过后返回既有后台 profile。

## 安全边界

- `two_factor_secret` 使用 Laravel encrypted cast 保存。
- `two_factor_recovery_codes` 只保存不可逆 hash，明文恢复码只在绑定成功时返回一次。
- Profile、审计 payload 和管理员列表不返回 secret 或恢复码 hash。
- 2FA 重置会递增目标账号 `session_version`，使旧会话失效。
- 本阶段不引入设备信任、短信验证码、邮件验证码、跳过白名单或命令行紧急重置。

## 验收命令

```bash
git diff --check
npm run build
./vendor/bin/sail artisan route:list --path=admin
./vendor/bin/sail artisan test --filter PhaseElevenTwoFactorTest
./vendor/bin/sail artisan test --filter AdminTwoFactorServiceTest
./vendor/bin/sail artisan test --filter PhaseFiveSecurityTest
./vendor/bin/sail artisan test
```

静态扫描：

```bash
rg -n "v-html|dangerouslyUseHTMLString|innerHTML|insertAdjacentHTML" resources/js/pages/admin/Login.vue resources/js/pages/admin/AdminUsers.vue resources/js/api/adminSecurity.ts resources/js/stores/adminAuth.ts
```
