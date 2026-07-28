# Ops Center Phase 35 — 告警值班进阶四合一

周期性值班轮转 + 多级升级链 + 告警分组聚合 + 值班仪表盘。

## 背景

phase 34 建了「绝对时间段值班 + 自动指派 + 指派通知 + SLA 达标率」。本阶段把值班与告警治理再进阶四块。

## 范围

### 1. 周期性值班轮转（镜像 phase-32 静默 recurrence）

- **表** `ops_on_call_shifts` 加 `recurrence`(once/daily/weekly) + `days_of_week`(json 0=日..6=六) + `start_time`/`end_time`(HH:MM)。
- **服务** `OnCallRotationService`：`matchesRecurrence()`/`timeInWindow()`（跨午夜 wrap）；**`currentOnCall()` 用 `->get()` 保持 `orderByDesc(starts_at)->orderByDesc(id)` 顺序，返回首个命中重复模式的班次**（保序选唯一值班人）；`create()` 校验 recurrence（daily/weekly 需时段、weekly 需星期）；`serialize()` 暴露 recurrence 字段 + `current`（叠加 matchesRecurrence）。
- **请求** `OnCallShiftStoreRequest` 加 5 条 recurrence 规则（`required_if`）。
- 前端 `OnCallSchedule.vue`：dialog recurrence radio + 时段 time-picker + weekly 星期 select；表按 recurrence 渲染 每天/每周/绝对。

### 2. 多级升级链（扩展 phase-23 单级）

- **`ops_alerts.escalation_level`** 新列（unsignedTinyInteger，默认 0）；`OpsAlert` fillable+cast；`serialize()` 暴露。
- **config**（部署期设，同 SLA 目标口径）`alerts.escalation_levels`：有序数组 `[{after_minutes, channels(空=全部启用通道), reassign_on_call}]`，默认 L1=30/L2=60(改派)/L3=120。保留 `escalation_enabled`(DB 设置) + `escalation_after_minutes`(config 空时的单级回退)。
- **`escalateStaleAlerts()`** 多级：对每条 open critical，`age=created_at→now`，`targetLevel`=达到阈值的最高级；若 `targetLevel > escalation_level` → 升级到 targetLevel（`escalated_at=now`、`escalation_level=target`、force-send 该级 channels、`reassign_on_call` 且有值班人则 `markAssigned` 改派、记 `escalated` 事件带 `level`）；否则已在该级且重推间隔（该级 after_minutes）已到 → 原级重推。`dryRun` 只返回集合。
- **`AlertNotificationService::send($alert, ?array $channels)`**：可选收窄到指定通道集（升级级别专属通道）；其余调用点不传=原行为。仍过 silence/severity 矩阵。
- 前端：告警表「已升级」tag 追加 `L{level}`。

### 3. 告警分组聚合（只读）

- **`AlertCenterService::groupedOpen($by)`**（`source|severity|assigned_to` 白名单，非法回退 source）：open 告警按维度 groupBy → 每组 `{group, total, critical/warning/info, assigned/unassigned, last_seen_at(max), samples(≤3 标题)}`，按 total 倒序。**不改 fingerprint 去重**（纯展示层）。
- **端点** `GET /alerts/groups?by=`（`ops.alerts.view`，GET 无审计）。
- 前端 `AlertCenter.vue`：「告警分组」面板，`el-segmented` 切维度 + `el-table` 展示 rollup。

### 4. 值班仪表盘（只读聚合）

- **`OnCallDashboardService::overview($days)`**（镜像 `SecurityOverviewService`，`Schema::hasTable` guard）：`current_on_call`、`upcoming_shifts`（active && starts_at>now，≤5）、`my_open_alerts`（当前值班人 open 计数+严重级分解）、`unassigned_open`、`sla`（`slaSummary` 摘 open_aging + ack/resolve overall rate + open_breaches）、`generated_at`。
- **端点** `GET /alerts/on-call/dashboard`（显式 `admin.permission:ops.alerts.view`）。
- 前端 `OnCallDashboard.vue` 新页（镜像 SecurityOverview）：统计卡（当前值班/我的待处理/未指派/违约）+ 未来班次表 + 我的严重级分解 + SLA 快照（积压桶 + 达标率）+ 7/30/90 天切换 + 刷新。侧边栏「值班仪表盘」入口。

## 权限 / 安全边界

- 值班 recurrence 复用 phase-32（跨午夜 wrap、生效范围 SQL 预筛 + 时段/星期叠加）；`currentOnCall` 保序选唯一值班人；boot-safe（表缺失→null）。
- 多级升级级别走 config（部署期设）、只对 open critical、force-send 仍过 silence/矩阵；改派复用 `markAssigned`（无新列）；`escalation_level` 单调不降；命令方法签名不变（`ScheduledCommandResilienceTest` 守）。
- 分组 + 仪表盘纯只读聚合，不改去重/生命周期；空数据安全；复用 `ops.alerts.view`（无新增 slug；仪表盘路由显式挂权限）。
- **本阶段无新增 POST 路由**（recurrence 复用现有 on-call POST，已审计）。

## 新增 env

```
OPS_ALERT_ESCALATION_L1_MINUTES=30
OPS_ALERT_ESCALATION_L2_MINUTES=60
OPS_ALERT_ESCALATION_L3_MINUTES=120
# 各级通道/改派在 config/ops.php 的 alerts.escalation_levels 调整
```

## 不做

- 值班「下次生效时间」预测、跨账号值班通知；升级级别的 DB 可视化编辑（走 config）、升级到外部 PagerDuty；分组的持久化 group 实体与 group 级 ack；仪表盘实时秒级刷新。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseThirtyFiveOnCallRecurrence|PhaseThirtyFiveMultiEscalation|PhaseThirtyFiveAlertGroup|PhaseThirtyFiveOnCallDashboard|PhaseTwentyThreeEscalation|PhaseThirtyFourOnCall|ScheduledCommandResilience'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
# 手动：建「每周三 09:00–18:00」值班→当天该时段 currentOnCall 命中；造 open critical 放置 >L2 阈值→ops:alerts:escalate 逐级升级+改派值班人；告警中心分组视图按来源聚合；值班仪表盘一屏看当前值班/待处理/SLA。
```

## 交付物

- 后端：迁移(on_call recurrence + escalation_level) + `OnCallRotationService`(recurrence/currentOnCall 保序) + `OnCallShiftStoreRequest` + `OpsOnCallShift`；`config`(escalation_levels) + `AlertCenterService`(escalateStaleAlerts 多级/groupedOpen/serialize escalation_level) + `AlertNotificationService::send($alert,?$channels)`；`AlertController::groups` + `OnCallDashboardService`/`OnCallDashboardController` + 2 路由。
- 前端：`opsStage4.ts`（recurrence/groups/dashboard/escalation_level 类型+函数）+ `OnCallSchedule.vue`(recurrence) + `AlertCenter.vue`(升级级别 tag + 分组面板) + `OnCallDashboard.vue` + 路由/侧边栏。
- 测试：`PhaseThirtyFiveOnCallRecurrence/MultiEscalation/AlertGroup/OnCallDashboard` + phase-23 升级扩展。
