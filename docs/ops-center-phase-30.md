# Ops Center Phase 30 — 告警处理 SLA 达标告警（超时未确认/未恢复 → sla_breach）

## 范围

phase 28 能回看 MTTA/MTTR，但不实时把关。本阶段补 **SLA 达标监控闭环**：定时扫描未闭环告警，按严重级的
**确认时限 / 恢复时限**判违约，命中升起一条独立的 `sla_breach` 治理告警（经现有通道 + 遵守 phase-29 静默），
原告警恢复后自动关闭对应违约告警。默认 opt-in 关闭。

## 与 phase-23 escalation 的区别

- escalation：`open && critical && 未确认>30min` → 重推**同一条**告警。仅 critical、仅确认维度。
- 本阶段：**所有严重级**、按档配 **ack + resolve 时限**、命中升起**新的** `sla_breach` 告警，覆盖确认/恢复两类，
  原告警闭环后自动 resolve 违约告警。两者互补可并存。

## 判据（`AlertCenterService::scanSlaBreaches`）

`config('ops.alerts.sla.enabled')` 关 → 空。查 `status IN ('open','acknowledged')` 且 `source NOT IN ('sla_breach','digest')`
（防自我递归 + 不对摘要计 SLA）；起点锚 `created_at`（稳定，同 phase 28）：

- **确认违约**：`status='open'`（未确认）且 `now-created_at >= ack_minutes[severity]`。
- **恢复违约**：未 resolved（open/acknowledged）且 `now-created_at >= resolve_minutes[severity]`。
- 命中任一 → `raiseSlaBreachAlert`（source=`sla_breach`、severity warning、指纹 `sla_breach:{原告警id}` 去重 + 冷却，
  context 带 `original_id`/`kinds`）。走 `send()` → **自动遵守 phase-29 静默**。
- `resolveClearedSlaBreaches`：原告警 `status='resolved'` 后，关闭其 open 的 `sla_breach`（事件 `sla_breach_cleared`）。

## 配置（env，opt-in）

| 变量 | 默认 | 说明 |
|---|---|---|
| `OPS_ALERT_SLA_ENABLED` | false | 启用开关 |
| `OPS_ALERT_SLA_ACK_MINUTES` | `10,30,120` | 确认时限（csv 顺序 critical,warning,info） |
| `OPS_ALERT_SLA_RESOLVE_MINUTES` | `60,240,1440` | 恢复时限（同上格式） |

由 `AlertCenterService::slaTargets` 解析成 per-severity map。命令 `ops:alerts:sla-scan {--dry-run}`，调度 `everyFiveMinutes`，容错（异常→退出码 0）。

## 前端

`GET /api/ops/alerts/sla`（phase 28）响应补 `targets`（enabled + 各严重级 ack/resolve 分钟）与 `open_breaches`（当前 open 的 sla_breach 数）。
「告警 SLA」页加只读「SLA 目标」表 + 有违约时的红色提示。违约本身是 `sla_breach` 告警，自动出现在告警中心。

## 安全边界 / 不做

- 排除 `sla_breach`/`digest` 源；起点锚 `created_at`；`sla_breach` 不进托管自动恢复源，原告警恢复由 `resolveClearedSlaBreaches` 显式关闭。
- 外发走 `send()` → 遵守 phase-29 静默；默认 opt-in 关闭；目标走 env，SLA 页只读展示。
- **不做**：per-alert 自定义 SLA（只按严重级）、达标率百分比报表、值班轮转/PagerDuty、UI 内改目标。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseThirtySlaAlert|ScheduledCommandResilience|AlertSla'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
# 手动：OPS_ALERT_SLA_ENABLED=true → 造 critical open 且 created_at 早于 ack 目标 → ops:alerts:sla-scan → 告警中心出现 SLA 违约 + 通道推送；resolve 原告警后再扫 → 违约告警自动关闭。
```
