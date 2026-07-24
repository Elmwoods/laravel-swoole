# Ops Center Phase 25 — 滥用来源自动封禁（失败登录暴增 IP → 临时封禁）

## 范围

把 phase-22（审计异常扫描）与 phase-24（IP 白/黑名单）接起来的**自动封禁闭环**：定时**按 IP**
统计失败登录，某来源 IP 在滚动窗口内超阈值 → 自动写一条**带过期时间的临时 `deny` 规则**，
经 phase-24 强制路径（登录入口 + `AdminAuthenticate` 中间件）**立即拦截**，到期**自动失效/解封**，
并升一条安全告警。默认 **opt-in 关闭**。

## 为什么要独立扫描器（不复用 phase-22）

phase-22 `AuditAnomalyScanService` 按 `admin_email` 优先聚合失败登录（IP 仅在 email 空时兜底），
且只升告警不封禁。自动封禁必须**以 IP 为主**聚合——攻击者换邮箱不换 IP 时才能命中。故新建独立
`AdminIpAutoBanService`，镜像 phase-22 的游标 / 首跑初始化 / 状态文件结构，但用 IP-primary 计数。
现有 `AdminLoginThrottleService` 只是 per-(email,ip) 的 15 分钟内存限流（429）——瞬时、不持久化、
不按纯 IP 聚合、不生成规则，与本阶段互补。

## 检测口径

- 计数查询：`admin_audit_logs` 中 `module='admin.auth' AND action IN ('login','login_locked') AND
  result='failure' AND created_at>=now()-window AND ip_address=<候选IP>`，`count >= threshold` → 封禁。
- 候选 IP 只取**游标之后的新失败登录行**的 IP（去重），对每个候选按 IP 在窗口内全量计数。
- `login_denied`（已封禁来源的 403）**不在** action 集合 → 已封 IP 的后续尝试不再累加，封禁到期后自然释放。

## 封禁 / 解封

- 封禁写 `admin_ip_rules`：`type=deny`、`cidr=<IP>`、`source=auto`、`expires_at=now()+ban_minutes`、
  `is_active=true`。通过 `AdminIpAccessService::autoBan()` 写入并 `flushCache()`（即时生效，不等 60s 快照）。
- **不覆盖人工规则**：同 `(deny, cidr)` 若已是 `source=manual` → 跳过（人工规则优先保留）；若已是 auto → 续期 `expires_at`。
- **过期即失效**：`evaluate()` 的快照查询加了 `(expires_at IS NULL OR expires_at > now())` 过滤 →
  过期 auto 规则立即不再匹配（最多 60s 快照延迟）。扫描每轮还顺带 `deleteExpiredAutoBans()` 清理表（不动人工/未过期）。

## 防自锁护栏（重要）

1. **默认 opt-in 关闭**（`OPS_AUTO_BAN_ENABLED=false`）。
2. **never-ban 名单**（`OPS_AUTO_BAN_NEVER_BAN`，默认含 loopback `127.0.0.1/8,::1`）跳过。
3. 命中任一启用 `allow` 规则的 IP **跳过**（白名单来源永不自封）。
4. 封禁**自动过期**（默认 60 分钟）。
5. 人工规则不被覆盖；phase-24 `admin:ip-access --flush` / `--disable` break-glass 仍可救。

## ⚠️ 反向代理前置条件

与 phase-24 同：无 `OPS_TRUSTED_PROXIES` 时 `request->ip()` 是**代理 IP**，自动封禁可能封掉代理 =
**锁死所有人**。**代理后未配 `OPS_TRUSTED_PROXIES` 前不要开自动封禁。**

## 配置（env）

| 变量 | 默认 | 说明 |
|---|---|---|
| `OPS_AUTO_BAN_ENABLED` | false | 启用开关种子（运行时被 DB 设置 `auto_ban_enabled` 覆盖，UI 可切换） |
| `OPS_AUTO_BAN_THRESHOLD` | 10 | 窗口内失败登录次数阈值 |
| `OPS_AUTO_BAN_WINDOW_MINUTES` | 10 | 滚动窗口分钟 |
| `OPS_AUTO_BAN_MINUTES` | 60 | 封禁时长（到期自动解封） |
| `OPS_AUTO_BAN_MAX_ROWS_PER_RUN` | 500 | 单次扫描审计行上限 |
| `OPS_AUTO_BAN_NEVER_BAN` | `127.0.0.1/8,::1` | 永不封禁的 IP/CIDR |

阈值/窗口/时长走 env（激进旋钮部署期设）；启用开关走 UI（安全管理 → 登录准入页）。

## 命令 / 调度

- `admin:ip-auto-ban {--dry-run} {--reset-cursor}`——`--dry-run` 只统计不封/不推进游标/不清理；容错（异常→退出码 0）。
- 调度 `routes/console.php`：`everyFiveMinutes()->withoutOverlapping()`。

## 告警

命中封禁升 `raiseAutoBanAlert`：`source='security_access'`、severity `warning`、事件 `ip_auto_banned`，
经现有通道推送。`security_access` **不在** `autoResolveRecoveredAlerts` 托管源 → 留人工确认。

## 前端

安全管理 → 登录准入页：策略卡加「启用自动封禁」开关 + 只读阈值/窗口/时长摘要 + 代理提示；
规则表加「来源」（手动/自动）与「过期」（时间 / 永久）两列。

## 安全边界 / 不做

- 一律带过期（无永久自动封禁）；只封 IP（不按邮箱/账号封）；无申诉流；无 GeoIP；UI 不调阈值（走 env）。

## 验收

```
docker … php vendor/bin/phpunit --filter 'AdminIpAutoBan|AdminIpAccess|PhaseTwentyFourIpAccess|ScheduledCommandResilience'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
# 手动：登录准入页开自动封禁 → 同一 IP 连续失败登录 ≥阈值 → admin:ip-auto-ban →
#       规则表出现该 IP（来源=自动、带过期）且立即被拦；到期自动放行；误封 admin:ip-access --flush。
```
