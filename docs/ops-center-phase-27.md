# Ops Center Phase 27 — 安全总览仪表盘

## 范围

phase 17–26 陆续加了登录风控、异常登录告警、活跃会话、审计异常、IP 名单、自动封禁、通道健康——
但这些数据散落在各自页面。本阶段加一个**只读安全总览仪表盘**：把各安全数据源聚合成统计卡 +
分项明细 + 失败登录趋势 + 近期安全事件时间线，一屏呈现「现在安全吗」。**纯聚合、只读**，不改任何既有安全逻辑。

> 本阶段先把 phase 24/25/26 合入 dev（仪表盘依赖其表），再从 dev 建。

## 聚合口径（数据源）

| 指标 | 表 / 口径 |
|---|---|
| 登录风控(7d) | `admin_login_events`：`is_new_ip`/`is_new_user_agent`/总数（近 7 天） |
| 失败登录(24h) | `admin_audit_logs`：`module='admin.auth' AND action IN(login,login_locked) AND result='failure'` 计数 + Top 10 来源 IP |
| 活跃会话 | `admin_sessions`：`revoked_at IS NULL` 计数 + distinct 管理员（含当前访问者的会话） |
| 2FA 覆盖率 | `admin_users`：active 总数 vs `two_factor_confirmed_at IS NOT NULL`（secret 是 encrypted 不能 SQL 过滤，用 confirmed_at 作 DB 代理）；`round(enabled/active*100)`，active=0 → 0 |
| 受信任设备 | `admin_trusted_devices`：`expires_at > now` 计数 |
| 开放安全告警 | `ops_alerts`：`status='open' AND source IN(security_login,security_audit,security_access,channel_health)` 按 source / severity |
| IP 封禁 | `admin_ip_rules`：active allow/deny（`is_active` 且未过期）；auto-ban = `source='auto' AND expires_at>now` |
| 通道健康 | `ops_channel_health`：按 status(healthy/failing/unknown) |
| 失败登录趋势 | `admin_audit_logs` 近 7 天 `DATE(created_at)` 聚合 |
| 近期安全事件 | 近 15 条上述 source 的 `ops_alerts`（`last_seen_at` 倒序）|

对 phase-24/26 的表用 `Schema::hasTable` 兜底（合并后都在，防御性）。

## 接口 / 权限

- `GET /api/ops/security/overview` → `SecurityOverviewService::overview()`，权限 **`ops.security.view`**（新增；super_admin 自动 + ops_admin + audit_viewer 只读可看）。
- 聚合模板复用 `AlertCenterService::summary()` 的 `clone` 逐指标 count + `groupBy` 写法。

## 前端

安全总览页（`resources/js/pages/ops/SecurityOverview.vue`，侧边栏「安全总览」）：
- 8 张统计卡（新 IP 登录 / 失败登录 / 活跃会话 / 2FA 覆盖率 / 受信任设备 / 开放安全告警 / IP 封禁 / 通道异常），状态 `el-tag` 按阈值着色。
- 分项：失败登录 Top IP 表、安全告警按来源表、通道健康 tag 组。
- 失败登录 7 天趋势 `echarts` 折线；近期安全事件 `el-timeline`。
- 手动「刷新」（不做秒级轮询）。纯文本、无 `v-html`。

## 安全边界 / 不做

- **纯只读聚合**：仪表盘只 SELECT，不新增任何可变操作。
- 权限 `ops.security.view`；正文只展示计数/IP/来源/标题（告警/审计正文本已脱敏），不泄漏 token/secret。
- 2FA 覆盖率用 `two_factor_confirmed_at` 作 DB 代理（secret 加密不可 SQL 过滤）。
- **不做**：跨页钻取联动、导出、自定义时间窗（固定 7d/24h）、实时秒级刷新、把 IP 名单/会话的**操作**搬进仪表盘（操作留各自页面）。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseTwentySevenSecurityDashboard'
docker … php vendor/bin/phpunit          # 全量回归（含合并后的 24/25/26）
npm run build
rg -n "v-html|innerHTML" resources/js/pages/ops/SecurityOverview.vue
# 手动：/admin/ops/security 看各卡；造几条失败登录/新 IP 登录/封禁规则后刷新，数字更新。
```
