# Ops Center 第五阶段交付说明

## 实现范围

- 独立后台管理员账号表，与默认 `users` 表隔离。
- 后台登录、退出、当前用户信息接口。
- 完全自定义 RBAC：管理员绑定角色，角色绑定系统白名单权限点。
- 首个超级管理员通过 Artisan 命令创建。
- `/api/ops/*` 默认要求后台登录，并按权限点控制访问。
- 管理员、角色权限、审计日志三个后台管理页面。
- Docker、Supervisor、Octane、告警处理、管理员和角色变更等敏感操作写入审计日志。
- 审计 payload 自动脱敏密码、Token、Cookie、Telegram 配置等敏感字段。

## 文件路径

- 后台安全表迁移：`/Users/ggbond/PHPProjects/swoole/database/migrations/2026_07_13_050000_create_admin_security_tables.php`
- 后台账号模型：`/Users/ggbond/PHPProjects/swoole/app/Models/AdminUser.php`
- 后台角色模型：`/Users/ggbond/PHPProjects/swoole/app/Models/AdminRole.php`
- 后台权限模型：`/Users/ggbond/PHPProjects/swoole/app/Models/AdminPermission.php`
- 审计日志模型：`/Users/ggbond/PHPProjects/swoole/app/Models/AdminAuditLog.php`
- 权限白名单服务：`/Users/ggbond/PHPProjects/swoole/app/Services/Admin/AdminPermissionRegistry.php`
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

- `POST /api/admin/auth/login`
- `POST /api/admin/auth/logout`
- `GET /api/admin/auth/me`

### 管理员管理

- `GET /api/admin/users`
- `POST /api/admin/users`
- `PUT /api/admin/users/{adminUser}`
- `POST /api/admin/users/{adminUser}/reset-password`

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

## 安全说明

- 后台账号独立于普通用户表，避免前后台身份混淆。
- 权限点由系统白名单维护，页面只允许角色勾选已有权限。
- `/api/ops/*` 不再公开访问，未登录返回 401，无权限返回 403。
- 审计日志会记录成功和失败操作，但不会保存密码、Token、Cookie、Telegram token、chat id 等敏感值。
- WebSocket 仍只推送轻量告警 payload，大日志继续通过 HTTP 权限接口读取。
