# Ops Center Phase 36 — 告警值班成熟度四合一

分组级批量操作 + 告警关联抑制 + 值班上岗提醒 + 告警统计周报。

## 背景

phase 35 有了告警分组视图、多级升级、值班仪表盘。本阶段补「行动闭环 + 降噪 + 值班触达 + 管理视图」。

## 范围

### 1. 分组级批量操作

- **`AlertCenterService::batchByGroup($by, $group, $op, $payload)`**（`by`∈source/severity，`op`∈acknowledge/assign）：选 `status='open' && {by}={group}` 的告警（cap 500），循环单条原语（`acknowledge`/`assign`），返回 `{op,by,group,affected,capped}`。复用单条原语 → 每条自动获得 timeline 事件 + SLA 配对。
- **`batchSilenceGroup($by,$group,$minutes)`**：复用 phase-29 `AlertSilenceService::create` 为该 source/severity 建一条时间窗静默（minutes clamp 1–1440，默认 60）。
- 端点 `POST /alerts/batch/{acknowledge,assign,silence}`（`ops.alerts.manage` + `admin.audit:ops.alerts,batch_*`）。前端分组面板行内「确认整组/指派/静默」按钮（`by=assigned_to` 时不显示批量列）。

### 2. 告警关联抑制

- **config** `alerts.correlation`：`enabled`(默认 false) + `dependencies`（`child_source => [parent_sources]` 静态拓扑，如 `queue=>[mysql,redis]`）。
- **`AlertCorrelationService::isSuppressed($alert)`**（boot-safe）：enabled 且 `dependencies[source]` 的任一父来源有 open 告警 → true。
- **迁移** `ops_alerts.suppressed_at`（nullable）；`storeAlert()` save 后标记；`AlertNotificationService::send()` 在静默 gate 后加抑制 gate（reason=suppressed，不 dispatch，仍入库/广播）；`serialize()` 暴露 `suppressed_at`；前端「被抑制」tag。
- 父来源仍照常告警；`sendTest`/`sendAssignment`/`sendOnCallReminder` 走 dispatch 不过 send gate，不受影响。

### 3. 值班上岗提醒

- **迁移** `ops_on_call_shifts.reminded_at`（nullable，去重）；**config** `alerts.on_call_reminder`（enabled 默认 false + lead_minutes 默认 15）。
- **`AlertNotificationService::sendOnCallReminder($assignee,$startsAt)`**（照 sendAssignment，内存态 info 告警）。
- **`OnCallRotationService::sendDueReminders()`**：仅 **once 绝对班次**（`is_active && recurrence='once' && starts_at∈[now,now+lead] && reminded_at NULL`）→ 推送 + 置 `reminded_at`。recurring 班次单 timestamp 无法按次去重，本阶段不覆盖。
- **命令** `ops:on-call:remind`（everyFiveMinutes，try/catch→SUCCESS）+ 韧性测试。

### 4. 告警统计周报

- **config** `alerts.weekly_report`（enabled 默认 false + window_days 7 + severity info + send_when_empty）。
- **`AlertCenterService::weeklyReportSummary($days)`**：组合 `digestSummary($days*24)` + `slaSummary($days)`（MTTA/MTTR/达标率/积压/违约）+ 当前值班；`renderWeeklyReport()` 纯文本；`sendWeeklyReport()`（config 门 + 空跳过 + 内存 alert + `notification->send()`）。
- **端点** `GET /alerts/report`（`ops.alerts.view`）；**命令** `ops:alerts:weekly-report {--days} {--dry-run}`（weeklyOn(1,'09:00')）+ 韧性测试。前端分组面板「统计周报」按钮 → dialog 预览。

## 权限 / 安全边界

- 批量复用单条原语（一致事件/SLA/广播）、`ops.alerts.manage` + 审计、cap 500、只作用 open；批量静默复用 phase-29（带时间窗）。
- 关联抑制 opt-in（config 拓扑）、只压外发（仍入库/广播/标 suppressed）、boot-safe（异常→不抑制）、父仍告警。
- 上岗提醒 opt-in、只 once 绝对班次、`reminded_at` 去重、内存态不落库。
- 周报 opt-in、纯只读聚合、GET 用 `ops.alerts.view`、命令 dry-run。

## 新增 env

```
OPS_ALERT_CORRELATION_ENABLED=false      # 依赖拓扑在 config/ops.php alerts.correlation.dependencies
OPS_ALERT_ON_CALL_REMINDER_ENABLED=false
OPS_ALERT_ON_CALL_REMINDER_LEAD_MINUTES=15
OPS_ALERT_WEEKLY_REPORT_ENABLED=false
OPS_ALERT_WEEKLY_REPORT_WINDOW_DAYS=7
```

## 不做

- 复杂关联规则引擎（只做来源级父子拓扑）、批量 resolve（避免误批量关闭）、recurring 班次上岗提醒、周报 PDF/Excel 导出与独立报表页、关联/依赖的 DB 可视化编辑（走 config）。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseThirtySixBatchOps|PhaseThirtySixCorrelation|PhaseThirtySixOnCallReminder|PhaseThirtySixWeeklyReport|ScheduledCommandResilience|PhaseFiveSecurity|PhaseTwentyNineAlertSilence'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
# 手动：分组「确认整组」disk；config 配 queue=>[mysql] 开 correlation → mysql open 时 queue 告警被抑制；建 once 班次 10min 后开 reminder → ops:on-call:remind；ops:alerts:weekly-report --dry-run；GET /alerts/report。
```

## 交付物

- 后端：`AlertCenterService`(batchByGroup/batchSilenceGroup/weeklyReportSummary/render/send/storeAlert 抑制标记) + `AlertBatchRequest` + `AlertController`(batch*/report) + 4 路由；`config`(correlation/on_call_reminder/weekly_report) + 迁移(suppressed_at/reminded_at) + `AlertCorrelationService` + `AlertNotificationService`(send 抑制 gate + sendOnCallReminder) + `OnCallRotationService::sendDueReminders` + `RemindOnCallCommand`/`SendAlertWeeklyReportCommand` + 调度。
- 前端：`opsStage4.ts`（batch/report/suppressed 类型+函数）+ `AlertCenter.vue`（分组批量按钮 + 被抑制 tag + 周报 dialog）。
- 测试：`PhaseThirtySixBatchOps/Correlation/OnCallReminder/WeeklyReport` + 韧性/安全扩展。
