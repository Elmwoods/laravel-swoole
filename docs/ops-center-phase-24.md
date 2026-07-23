# Ops Center Phase 24 — 后台登录 IP 白/黑名单（CIDR 准入）

## 范围

给后台登录加**网络层准入控制**：按客户端 IP 的 CIDR 白/黑名单放行或拒绝登录，并在
`AdminAuthenticate` 中间件对**已登录会话**同样强制（IP 被拉黑 / 移出白名单的活跃会话
下次请求即被踢下线）。延续 phase-17 登录加固主题，复用 CLI break-glass、审计、全局设置模式。

## 名单模式

- **黑名单（blocklist，默认）**：命中任一启用的 `deny` 规则 → 拒绝；否则放行（空名单 = 全部放行）。
- **白名单（allowlist）**：命中任一启用的 `allow` 规则 → 放行；**无任何启用的 allow 规则 →
  fail-open 放行**（绝不把所有人锁死）；有 allow 规则但都不命中 → 拒绝。
- **`deny` 始终优先于 `allow`**（白名单里也可用 deny 精确挖洞）。
- 全局「启用开关 + 模式」默认写在 `config('ops.security.ip_access')`，运行时可被
  `admin_security_settings` 表覆盖（后台 UI 可切换）。默认 **opt-in（关闭）**，不改现有登录行为。

## 数据表

- `admin_ip_rules`：`type`(allow/deny)、`cidr`(单 IP 或 CIDR，v4/v6)、`label`、`is_active`、
  `created_by`、时间戳；`unique(type, cidr)`。
- `admin_security_settings`：镜像 `ops_alert_settings` 的 key/value/description 全局键值表，
  承载 `ip_access_enabled`、`ip_access_mode` 两个标量（config 作默认真值来源）。

## 枚举点 / 强制

- **登录入口** `AdminAuthController::login()`：在取得 `$ip` 之后、限流之前评估准入，命中拒绝
  → 审计 `admin.auth / login_denied / failure`（HTTP 403）。
- **中间件** `AdminAuthenticate`：在用户存在性检查之后新增 IP 门，被拒 → `logout + invalidate +
  regenerateToken`（HTTP 401，文案「您的 IP 不在允许访问后台的范围。」）。覆盖 bootstrap /
  version / registry / idle 所有后续分支。
- **CIDR 匹配**复用 Symfony `IpUtils::checkIp`（v4/v6 通吃）；存储的非法 CIDR 容错跳过。
- **性能**：每请求评估走 60s 缓存（`admin_ip_access_snapshot`）+ 写时失效，不引入每请求 DB 压力。

## 接口（权限 `admin.security.manage`，默认仅超管）

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/admin/ip-rules` | 返回 settings + rules + 当前客户端 IP |
| POST | `/api/admin/ip-rules` | 新增规则（CIDR 非法 → 422） |
| PATCH | `/api/admin/ip-rules/{rule}` | 启用 / 停用规则 |
| DELETE | `/api/admin/ip-rules/{rule}` | 删除规则 |
| PUT | `/api/admin/ip-access/settings` | 更新启用开关 + 模式 |

所有变更显式写审计（`admin.security` 模块）。前端页面「登录准入」在 安全管理 子菜单下。

## Break-glass（自锁自救）

管理员把自己 IP 锁在名单外时，从服务器 CLI 恢复，无需登录后台：

```
sail artisan admin:ip-access --status      # 查看启用/模式/规则计数
sail artisan admin:ip-access --disable     # 紧急关闭准入（放行全部）
sail artisan admin:ip-access --flush       # 清空所有规则
sail artisan admin:ip-access --allowlist   # 切换白名单
sail artisan admin:ip-access --blocklist   # 切换黑名单
```

## ⚠️ 反向代理前置条件（务必）

仓库默认**不信任任何代理**。Octane/Swoole 常在 nginx 之后，此时 `request->ip()` 是**代理 IP**，
名单会失效或误判。要按**真实客户端 IP** 生效，必须把可信代理注入为**真实环境变量**：

```
OPS_TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12   # 或单个 '*' 信任全部（仅在入口可信时）
```

配置后 `bootstrap/app.php` 会信任 `X-Forwarded-For`，`request->ip()` 全局回归真实客户端
（审计 / 限流 / 会话注册一并受益）。详见 `docs/ops-center-deploy-runbook.md`。

## 安全边界 / 不做

- 准入名单是全局策略（非 owner-scoped），仅 `admin.security.manage` 可管理。
- 多重防自锁：默认 opt-in、白名单空规则 fail-open、CLI break-glass。
- **不做**：GeoIP / 地区级准入、按管理员/角色差异化名单、滥用来源自动进黑名单、准入命中的
  告警推送（本阶段只写审计）、UI 内编辑既有规则的 CIDR（改用删+建）。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseTwentyFourIpAccess|AdminIpAccess|AdminAuthenticate'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
# 手动：登录准入页加 deny=<自己IP> → 立即被踢；sail artisan admin:ip-access --flush 恢复。
```
