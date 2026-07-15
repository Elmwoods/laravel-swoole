# Ops Center 第五阶段发布验收清单

## 发布目标

第五阶段交付后台独立账号、后台登录、RBAC 权限、审计闭环、登录安全、会话超时和审计日志治理。本清单用于发布前复核、发布后验收和回滚沟通，不新增业务功能。

## 发布前检查

### 必跑命令

按顺序执行，避免多个 PHPUnit 进程同时刷新同一个 `testing` 数据库。

```bash
git diff --check
npm run build
./vendor/bin/sail artisan route:list --path=ops
./vendor/bin/sail artisan test --filter PhaseFiveSecurityTest
./vendor/bin/sail artisan test --filter AdminSecurityServiceTest
./vendor/bin/sail artisan test
```

静态敏感检查需要覆盖以下风险点：

- 前端不允许启用密码明文展示开关。
- 前端不允许直接输出原始错误对象。
- 请求封装不允许把明文密码或确认密码作为回退 payload 提交。

预期结果：

- `npm run build` 退出码为 0。
- `route:list --path=ops` 能列出 Ops 路由。
- `PhaseFiveSecurityTest`、`AdminSecurityServiceTest` 和完整测试均通过。
- 静态敏感检查无命中，且检查命令本身不要写入被扫描目录造成假阳性。
- 如果 Docker/Podman 未启动，先启动后再跑 Sail 命令，不将环境失败记为测试通过。

### 首个超级管理员

首个后台超级管理员只能通过 Artisan 命令创建，不在迁移、Seeder 或代码中写默认密码。

```bash
./vendor/bin/sail artisan admin:create-super \
  --name="Ops Admin" \
  --email="ops@example.com" \
  --password="change-me-strong-password"
```

发布注意事项：

- 使用临时强密码创建后，应由受控渠道交付给负责人并立即轮换。
- 确认至少保留一个启用状态的 `super_admin` 管理员。
- 不要把命令中的真实密码写入文档、工单、聊天记录或审计备注。

### 审计日志清理

默认保留 180 天审计日志；发布前建议先 dry-run。

```bash
./vendor/bin/sail artisan admin:audit-prune --days=180 --dry-run
./vendor/bin/sail artisan admin:audit-prune --days=180
```

规则：

- `--days` 范围为 30 到 3650。
- `--dry-run` 只输出将删除数量，不删除数据。
- 清理命令本身不写入 `admin_audit_logs`，避免清理时产生循环审计。
- 审计日志删除不可逆，生产执行前应确认备份策略。

## 生产配置

### 密码请求加密私钥

生产环境建议配置统一 RSA 私钥，避免多实例各自生成本地私钥导致公钥和私钥不一致。

```env
ADMIN_PASSWORD_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
```

要求：

- 使用 4096 位 RSA 私钥。
- 多实例部署必须使用同一私钥配置。
- 私钥不得提交到 Git，不得输出到日志。
- 如果未配置，系统会在 `storage/app/private/admin-password-private.pem` 自动生成本地私钥；该方式只适合单实例或本地环境。

### Session、Cache、Queue

生产环境需要确认：

- `SESSION_DRIVER` 使用可被所有应用实例访问的后端，例如 database 或 Redis。
- `CACHE_STORE` 使用稳定后端，避免登录限流和运行状态在多实例间不一致。
- `QUEUE_CONNECTION` 与 Supervisor/队列进程配置一致。
- 修改 `.env` 或 config 后需要清理配置缓存并重启 Octane/Swoole。

建议发布命令：

```bash
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan cache:clear
```

### Octane / Swoole

发布后需要重启长驻进程，避免旧代码、旧配置或旧容器状态继续驻留。

检查点：

- Octane/Swoole 已重启。
- 队列 worker 已重启。
- Supervisor 配置未暴露敏感环境变量。
- Docker、Supervisor、Octane 控制接口仍受后台登录和权限点保护。

## 权限与审计验收

发布后用后台账号验证：

- 未登录访问 `/api/ops/dashboard` 返回 401。
- 登录后访问 `/api/admin/auth/me` 返回当前后台账号、角色和权限。
- 无权限账号访问 Ops 或安全管理接口返回 403。
- 已登录但无任何可访问菜单时进入 `/admin/ops/no-permission`。
- Docker、Supervisor、Octane、告警处理、管理员变更、角色变更、密码重置等敏感操作写入审计日志。
- 审计日志 payload 不包含密码、token、cookie、Telegram token、chat id 或密钥。

## 回滚说明

代码回滚前需要评估数据状态：

- 第五阶段新增的后台表、pivot 表和审计表不会因代码回滚自动删除。
- `admin_users.password_changed_at` 和 `admin_users.session_version` 字段不会因代码回滚自动删除。
- 超级管理员重置密码后，目标管理员旧 session 已失效；该失效不可逆，需要重新登录。
- 审计日志清理删除的数据不可逆，执行前应确认备份。
- 如果回滚到不支持后台独立账号的版本，需要确认后台入口是否下线或切回旧保护方式。

回滚后复核：

```bash
./vendor/bin/sail artisan route:list --path=ops
./vendor/bin/sail artisan test
npm run build
```

## 已知发布风险

- 多实例未配置统一 `ADMIN_PASSWORD_PRIVATE_KEY` 会导致密码密文无法在不同实例解密。
- 并行执行多个会刷新数据库的 PHPUnit 命令会互相干扰同一个 `testing` 数据库；发布验收测试应顺序执行。
- `public/build` 为构建产物且被 Git 忽略，部署系统需要按现有流程生成或携带前端构建产物。
- Vite/Rolldown 构建可能出现依赖注释和大 chunk 警告；只要退出码为 0，不阻断本阶段发布。
