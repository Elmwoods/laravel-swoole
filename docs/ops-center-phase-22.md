# Ops Center Phase 22 — 审计异常检测告警

> 定时扫描审计日志 → 命中失败登录暴增 / 敏感操作 → 升安全告警

## 范围

后台把管理操作/登录都写进 `admin_audit_logs`，但无人主动盯。本阶段加定时扫描：命中可疑模式时升 `security_audit` 告警（进现有告警中心 + phase-14 通道推送）。无新表（仅一个游标状态文件）、无前端（告警自动出现在告警中心，同 phase-18 登录告警）。

## 检测规则

1. **失败登录暴增**：`module='admin.auth' AND action IN ('login','login_locked') AND result='failure'`，按 `admin_email`（失败登录记的是提交邮箱，无则 IP）在滚动窗口内计数 ≥ 阈值 → **critical**。fingerprint `audit:failed_login:{subject}`（同主体去重，重复增 hit_count、按冷却再推）。
2. **敏感操作**：`(module:action)` 白名单命中的**新**审计行 → **warning**。fingerprint `audit:sensitive:{审计行id}`（每行唯一）。默认白名单：`admin.users:create, admin.users:reset_password, admin.users:two_factor_reset, admin.users:two_factor_reset_cli, admin.roles:create, admin.roles:update`。

## 游标 / 去重

`admin_audit_logs` 单调自增 → 游标 `last_id` 存 JSON 状态文件（`storage_path('app/ops-audit-anomaly-state.json')`，镜像 `OpsLogErrorWatcherService` 状态模式）。每次扫描只取 `id > last_id` 的新行（限 `max_rows_per_run`），处理后推进游标。**首跑无状态时游标初始化为当前 `max(id)`**，只对上线后的新行告警（不泛滥历史）；`--reset-cursor` 重置。失败登录暴增触发时对该主体做窗口计数（回看窗口内所有行）。

## 命令 / 调度

```bash
ops:audit:scan-anomalies {--dry-run} {--reset-cursor}
```
- `--dry-run` 只统计、不发送、不推进游标。
- 瞬时异常 → `warn` + 退出码 0（容错，防自澎环，`ScheduledCommandResilienceTest` 守）。
- 调度：`routes/console.php` 每 5 分钟（`everyFiveMinutes`）。

## 新增配置

`config/ops.php` 的 `alerts.audit_anomaly`：

| 键 | env | 默认 |
|---|---|---|
| `enabled` | `OPS_AUDIT_ANOMALY_ENABLED` | `true` |
| `window_minutes` | `OPS_AUDIT_ANOMALY_WINDOW_MINUTES` | `10` |
| `failed_login_threshold` | `OPS_AUDIT_ANOMALY_FAILED_LOGIN_THRESHOLD` | `5` |
| `sensitive_actions` | `OPS_AUDIT_ANOMALY_SENSITIVE_ACTIONS`（csv `module:action`） | 见上 |
| `max_rows_per_run` | `OPS_AUDIT_ANOMALY_MAX_ROWS_PER_RUN` | `500` |
| `state_file` | — | `storage/app/ops-audit-anomaly-state.json` |

## 安全边界 / 不做

- `security_audit` 告警只经**已启用通道**推送、severity 经矩阵路由；正文脱敏（审计 payload 本已脱敏）；不进 `autoResolveRecoveredAlerts` 托管源，留人工确认。
- 游标首跑初始化 + `max_rows_per_run` + fingerprint 去重 + 冷却，防泛滥/刷屏。
- **不做**：前端页面、超管创建检测（`CreateSuperAdminCommand` 目前不写审计，补审计后再纳入）、UI 配置规则。

## 验收

```bash
docker … php vendor/bin/phpunit --filter 'PhaseTwentyTwoAuditAnomaly|ScheduledCommandResilience'
docker … php vendor/bin/phpunit          # 全量回归
docker … php vendor/bin/pint <改动文件>
npm run build
# 手动：sail artisan ops:audit:scan-anomalies --dry-run；连续多次失败登录后再跑 → 告警中心出现「失败登录暴增」critical。
```
