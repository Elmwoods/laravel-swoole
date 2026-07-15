# Ops Center 第五阶段交付说明

## 实现范围

- 独立后台管理员账号表，与默认 `users` 表隔离。
- 后台登录、退出、当前用户信息接口。
- 完全自定义 RBAC：管理员绑定角色，角色绑定系统白名单权限点。
- 首个超级管理员通过 Artisan 命令创建。
- `/api/ops/*` 默认要求后台登录，并按权限点控制访问。
- 后台登录失败限流：同一邮箱和 IP 组合 15 分钟内最多 5 次失败，第 6 次返回 429。
- 超级管理员重置密码后，目标管理员旧会话立即失效。
- 只有启用状态的 `super_admin` 角色持有者可以重置任一管理员密码；普通管理员即使拥有 `admin.users.manage` 也不能改密码。
- 前端密码输入不提供明文显示按钮，请求错误日志不输出请求体。
- 后台登录、新增管理员、重置密码请求均使用 RSA-OAEP 密文密码字段，前端请求 payload 不再提交明文密码。
- 管理员、角色权限、审计日志三个后台管理页面。
- Docker、Supervisor、Octane、告警处理、管理员和角色变更等敏感操作写入审计日志。
- 审计 payload 自动脱敏密码、Token、Cookie、Telegram 配置等敏感字段。

## 文件路径

- 后台安全表迁移：`/Users/ggbond/PHPProjects/swoole/database/migrations/2026_07_13_050000_create_admin_security_tables.php`
- 登录安全字段迁移：`/Users/ggbond/PHPProjects/swoole/database/migrations/2026_07_13_060000_add_login_security_fields_to_admin_users_table.php`
- 后台账号模型：`/Users/ggbond/PHPProjects/swoole/app/Models/AdminUser.php`
- 后台角色模型：`/Users/ggbond/PHPProjects/swoole/app/Models/AdminRole.php`
- 后台权限模型：`/Users/ggbond/PHPProjects/swoole/app/Models/AdminPermission.php`
- 审计日志模型：`/Users/ggbond/PHPProjects/swoole/app/Models/AdminAuditLog.php`
- 权限白名单服务：`/Users/ggbond/PHPProjects/swoole/app/Services/Admin/AdminPermissionRegistry.php`
- 登录限流服务：`/Users/ggbond/PHPProjects/swoole/app/Services/Admin/AdminLoginThrottleService.php`
- 密码请求加密服务：`/Users/ggbond/PHPProjects/swoole/app/Services/Admin/AdminPasswordCryptoService.php`
- 审计服务：`/Users/ggbond/PHPProjects/swoole/app/Services/Admin/AdminAuditService.php`
- 后台认证控制器：`/Users/ggbond/PHPProjects/swoole/app/Http/Controllers/Admin/Auth/AdminAuthController.php`
- 管理员/角色/审计控制器：`/Users/ggbond/PHPProjects/swoole/app/Http/Controllers/Admin/Security`
- 后台认证与权限中间件：`/Users/ggbond/PHPProjects/swoole/app/Http/Middleware`
- 首个超级管理员命令：`/Users/ggbond/PHPProjects/swoole/app/Console/Commands/Admin/CreateSuperAdminCommand.php`
- API 路由：`/Users/ggbond/PHPProjects/swoole/routes/api.php`
- 前端 API：`/Users/ggbond/PHPProjects/swoole/resources/js/api/adminSecurity.ts`
- 前端登录与安全页面：`/Users/ggbond/PHPProjects/swoole/resources/js/pages/admin`
- 前端路由守卫：`/Users/ggbond/PHPProjects/swoole/resources/js/router/index.js`
- 后台布局菜单：`/Users/ggbond/PHPProjects/swoole/resources/js/layouts/AdminLayout.vue`
- Feature 测试：`/Users/ggbond/PHPProjects/swoole/tests/Feature/Ops/PhaseFiveSecurityTest.php`
- Unit 测试：`/Users/ggbond/PHPProjects/swoole/tests/Unit/Admin/AdminSecurityServiceTest.php`

## API 文档

### 后台认证

- `GET /api/admin/auth/password-key`
  - 返回当前后台密码加密公钥、key id 和算法。
  - 不返回私钥。
- `POST /api/admin/auth/login`
  - 登录失败 5 次后，15 分钟窗口内返回 429。
  - 登录成功会清除该邮箱/IP 的失败计数。
  - 请求字段使用 `password_encrypted` 和 `password_key_id`，不再接受明文 `password`。
- `POST /api/admin/auth/logout`
- `GET /api/admin/auth/me`

### 管理员管理

- `GET /api/admin/users`
- `POST /api/admin/users`
- `PUT /api/admin/users/{adminUser}`
- `POST /api/admin/users/{adminUser}/reset-password`
  - 仅 `super_admin` 可调用。
  - 重置后目标管理员 `session_version` 递增，旧会话访问后台接口返回 401。
  - 新增管理员和重置密码均使用密文密码字段。

### 角色权限

- `GET /api/admin/roles`
- `POST /api/admin/roles`
- `PUT /api/admin/roles/{adminRole}`

### 审计日志

- `GET /api/admin/audit-logs`
- 查询参数：`admin_user_id`、`module`、`action`、`result`、`from`、`to`、`page`、`per_page`

## 权限点

- `ops.dashboard.view`
- `ops.logs.view`
- `ops.alerts.view`
- `ops.alerts.manage`
- `ops.docker.view`
- `ops.docker.control`
- `ops.supervisor.view`
- `ops.supervisor.control`
- `ops.system.view`
- `admin.users.manage`
- `admin.roles.manage`
- `admin.audit.view`

## 首个超级管理员

```bash
php artisan admin:create-super \
  --name="Ops Admin" \
  --email="ops@example.com" \
  --password="change-me-strong-password"
```

## Docker + Sail + Octane 测试方法

```bash
sail artisan migrate
sail artisan admin:create-super --name="Ops Admin" --email="ops@example.com" --password="change-me-strong-password"
sail artisan route:list --path=ops
sail artisan test --filter PhaseFiveSecurityTest
sail artisan test --filter AdminSecurityServiceTest
sail artisan test
npm run build
```

## 密码请求加密配置

生产环境建议在 `.env` 配置后台密码请求私钥：

```env
ADMIN_PASSWORD_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
```

如果未配置，系统会在 `storage/app/private/admin-password-private.pem` 自动生成本地 RSA 私钥。该文件必须限制访问权限，并且多实例部署时应改用统一环境变量私钥，避免不同实例公私钥不一致。
私钥建议使用 4096 位 RSA，以兼容当前 255 位密码长度上限。

本次密码请求加密不涉及数据库字段变更，无需新增 SQL。

## 安全说明

- 后台账号独立于普通用户表，避免前后台身份混淆。
- 权限点由系统白名单维护，页面只允许角色勾选已有权限。
- `/api/ops/*` 不再公开访问，未登录返回 401，无权限返回 403。
- 登录限流 key 使用小写邮箱和 IP，防止大小写绕过计数。
- 后台 session 记录登录时的 `session_version`，密码重置后版本不一致会强制重新登录。
- 重置密码入口同时做权限点和超级管理员角色校验，避免普通用户管理员扩大密码管理权限。
- 密码只允许作为请求输入进入服务端，接口响应、审计日志、前端错误日志均不得展示或记录明文密码。
- 前端使用 `/api/admin/auth/password-key` 获取公钥后，通过 WebCrypto 加密密码；请求体只包含密文和 key id。
- 后端只在内存中短暂解密密码用于校验或生成 hash，不返回、不记录明文。
- 审计日志会记录成功和失败操作，但不会保存密码、Token、Cookie、Telegram token、chat id 等敏感值。
- WebSocket 仍只推送轻量告警 payload，大日志继续通过 HTTP 权限接口读取。
