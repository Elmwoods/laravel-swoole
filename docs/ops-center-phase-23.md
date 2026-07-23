# Ops Center Phase 23 — 告警升级 / 未确认重推

> open critical 告警超时无人确认 → 定时重推，避免被遗漏

## 范围

手动管理的 critical 告警（inspection / security_audit 失败登录暴增等）只在升起时推一次，之后无人确认也不再提醒（这些 source 不进 `autoResolveRecoveredAlerts`，长期挂 open）。本阶段加**升级闭环**：定时扫描 `status=open && severity=critical` 且超时无人确认的告警 → **强制重推**（绕过冷却）+ 记 `escalated` 事件 + 广播 + 标记升级时间。

## 判定 / 去重

- **超时判定用 `created_at`**（唯一稳定的 open-since；`last_seen_at`/`updated_at` 在每次 re-fire 都刷新，不能用）。
- 命中条件：`status='open' AND severity='critical' AND created_at <= now()-after` 且 `(escalated_at IS NULL OR escalated_at <= now()-after)`——首次超时升级，之后每 `after` 分钟再升级一次，直到被确认（`acknowledged`/`resolved` 即退出）。
- `escalated_at` 作再升级间隔锚点，防每分钟重复升级。

## 升级动作

`AlertCenterService::escalateStaleAlerts(bool $dryRun=false)`（镜像 `autoResolveRecoveredAlerts`）：setting 门控 → 查命中告警 → 逐行 `escalated_at=now()` → `notification->send()`（强制重推，不过 `notification_repeat_minutes` 冷却）→ `recordAlertEvent('escalated','ops-escalator',...)` → `broadcast(AlertTriggered)`。**不提升严重级**（critical 已最高）、**不加独立升级通道**（通知层无 per-alert 通道 seam）。

## 命令 / 调度 / 设置

- 命令 `ops:alerts:escalate {--dry-run}`：`--dry-run` 只统计不重推/不标记；容错（异常 warn + 退出码 0，`ScheduledCommandResilienceTest` 守）。
- 调度：`routes/console.php` 每 5 分钟（`everyFiveMinutes`）。
- 设置走 **OpsAlertSetting**（与 `auto_resolve_grace_minutes` 同构，告警设置页可调）：`escalation_enabled`（默认 true）、`escalation_after_minutes`（默认 30），config `ops.alerts.thresholds.escalation_*` 作默认种子；`AlertCenterService::updateSettings` 白名单 + `AlertSettingsUpdateRequest` 校验（bool / integer 1–1440）。

## 前端

告警设置面板加「升级重推」开关 + 分钟数（`AlertCenter.vue`）；告警列表对 `escalated_at` 有值的行显示「已升级」`el-tag`。`opsStage4.ts` 的 `AlertSettings`/`OpsAlert` 类型补 `escalation_*` / `escalated_at`。纯文本、无 `v-html`。

## 安全边界 / 不做

- 只处理 open critical；acknowledged/resolved 不动；只经已启用通道推送、severity 经矩阵路由（critical 行）。
- **不做**：提升严重级、独立升级通道、值班轮转 / PagerDuty 集成。

## 验收

```bash
docker … php vendor/bin/phpunit --filter 'PhaseTwentyThreeEscalation|ScheduledCommandResilience|PhaseEightAlert'
docker … php vendor/bin/phpunit          # 全量回归
docker … php vendor/bin/pint <改动文件>
npm run build
rg -n "v-html|innerHTML" resources/js/pages/ops/AlertCenter.vue
# 手动：造一条 open critical 且 created_at 早于阈值 → sail artisan ops:alerts:escalate → 告警中心该条「已升级」+ 通道再收到。
```
