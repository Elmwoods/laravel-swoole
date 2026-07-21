# Ops Center Phase 17 — 登录安全加固

> break-glass 紧急重置 · 登录风控 · TOTP 重放保护 · 设备信任

## 范围

phase 11 上线了强制 2FA，本阶段补齐四个登录安全缺口，并新增一个自助「账号安全」面：

1. **TOTP 重放保护** —— 记录每个管理员最近使用的时间步，同一 6 位验证码在 ±30s 有效窗口内不可重放。
2. **break-glass 紧急重置** —— CLI 命令可无条件重置任意管理员（含唯一超管自己）的 2FA，解决 authenticator + 恢复码全部丢失后永久锁死的问题。
3. **登录风控 / 异地登录记录** —— 每次成功登录落库，标记新 IP / 新设备，管理员可自助查看最近登录历史。
4. **设备信任** —— 可选「记住此设备 30 天」，本设备后续登录跳过 2FA 挑战；可自助查看与撤销。

## 后端改动

### 迁移

- `2026_07_21_090000_add_two_factor_last_used_step_to_admin_users_table` —— `admin_users.two_factor_last_used_step`（unsignedBigInteger, nullable）。
- `2026_07_21_091000_create_admin_login_events_table` —— `admin_login_events`。
- `2026_07_21_092000_create_admin_trusted_devices_table` —— `admin_trusted_devices`。

### 服务

- `AdminTwoFactorService`
  - `matchStep(secret, code, ?ts): ?int` —— 命中返回时间步，未命中返回 null。
  - `verifyTotp()` 改为 `matchStep(...) !== null`。
  - `verifyLoginTotp(AdminUser, code): bool` —— 登录挑战专用，命中且时间步严格大于上次已用步才通过，通过即记录。
  - `enable(..., ?int $usedStep = null)` —— 绑定确认时记录确认步，绑定码不可作首登挑战重放。
  - `reset()` —— 清空 2FA 与 `two_factor_last_used_step`、递增 `session_version`，并撤销该管理员**全部**受信任设备。
- `AdminLoginEventService::record(AdminUser, Request, bool $trusted)` / `history(AdminUser, limit)`。
- `AdminTrustedDeviceService::issue / findValid / touch / revoke / revokeAll / list`。仅存 token 的 SHA-256 摘要，明文只在 cookie 中。

### 接口

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/api/admin/auth/two-factor/challenge` | 新增可选 `trust_device`（bool）；校验走 `verifyLoginTotp`（重放保护）。 |
| POST | `/api/admin/auth/two-factor/confirm` | 新增可选 `trust_device`（bool）。 |
| POST | `/api/admin/auth/login` | 带有效受信任设备 cookie 时跳过 2FA 直接登录。 |
| GET | `/api/admin/auth/login-history` | 本人最近 20 条登录历史（`admin.auth`）。 |
| GET | `/api/admin/auth/trusted-devices` | 本人未过期受信任设备（`admin.auth`）。 |
| POST | `/api/admin/auth/trusted-devices/{device}/revoke` | 撤销本人一台受信任设备（`admin.auth`）。 |

### CLI

```bash
sail artisan admin:reset-two-factor --email=<管理员邮箱>
```

- 清空该账号 2FA（secret / 恢复码 / 已用步）、递增 `session_version`（使旧会话失效）、撤销其全部受信任设备。
- **无「不能重置自己」限制**（正是 break-glass 的意义）。
- 记录一条审计（`module=admin.users`，`action=two_factor_reset_cli`，`actor=cli`）。
- 邮箱非法或不存在 → 退出码 1，不写审计。

## 前端改动

- `resources/js/pages/admin/Login.vue` —— 2FA 挑战步新增「记住此设备 30 天」勾选，传 `trust_device`。
- `resources/js/pages/admin/AccountSecurity.vue`（新）—— 2FA 状态、登录历史（新 IP / 新设备徽标）、受信任设备列表 + 撤销。
- `resources/js/api/accountSecurity.ts`（新）。
- 路由 `/admin/ops/account-security`（任意已登录管理员可见，无需权限点）+ 侧边栏「账号安全」。
- 纯文本渲染，无 `v-html`。

## 安全边界 / 不做

- 受信任设备 cookie 承载高熵随机 token，服务端仅存其 SHA-256 摘要；cookie 为 httpOnly、生产 secure、`SameSite=Lax`、30 天过期、可撤销、per-device，且在 `EncryptCookies` 白名单中（契约清晰，安全性由上述属性保证）。
- 自助接口只操作 `request->user('admin')` 本人数据，不跨账号。
- 设备信任是**便利换安全**：30 天上限、可撤销、2FA 重置即全撤；不做「永久信任」。
- **不做**：短信/邮件二次验证码、真实 GeoIP 解析（`is_new_ip` 只按 IP 变化判定）、新 IP 登录通知推送（可复用后续告警通道）。
- 不新增权限点。

## 验收

```bash
# 单元 + 功能
docker … php vendor/bin/phpunit --filter 'TwoFactor|ResetTwoFactor|LoginEvent|TrustedDevice'
docker … php vendor/bin/phpunit            # 全量回归
docker … php vendor/bin/pint <改动文件>
npm run build

# 手动
sail artisan admin:reset-two-factor --email=<超管>     # 2FA 清空、旧会话失效
#   重放：同一 TOTP 码二次挑战 → 422
#   风控：换 IP 登录 → 登录历史标「新 IP」
#   设备信任：勾「记住此设备」后再登跳过 2FA；撤销后恢复需 2FA

# 静态扫描
rg -n "v-html|innerHTML" resources/js/pages/admin/AccountSecurity.vue resources/js/pages/admin/Login.vue
```
