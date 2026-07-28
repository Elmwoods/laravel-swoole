# Ops Center Phase 34 — 告警值班运营四合一

值班排班自动指派 + 指派通知推送 + 每通道模板 + SLA 达标率。

## 背景

phase 33 让告警能被手动指派、能自定义一个全局通知模板。本阶段补齐值班闭环 + 通知精细化 + SLA 治理四块（互相独立，围绕告警中心）。

## 范围

### 1. 值班排班自动指派

- **表** `ops_on_call_shifts`：`assignee`(自由串120) + `label` + `starts_at`/`ends_at`(绝对时间段) + `is_active` + `created_by`。采用**绝对时间段窗口**（轮转=按序建多段班次）。
- **服务** `OnCallRotationService`：`currentOnCall()` 解析此刻生效、最近开始的班次值班人（boot-safe，表缺失→null）；`list/create/toggle/delete` CRUD。
- **自动指派**：`AlertCenterService::storeAlert()` 在新告警 `save()` 后，若 `wasRecentlyCreated` && 未指派 && `config('ops.alerts.on_call.enabled')` && 有当前值班人 → 调私有 `markAssigned()` 设 `assigned_to` + 记 `assigned` 事件（actor `on-call-auto`）。**仅规则引擎路径**（inspection/sla_breach 等系统恢复类 raiser 不覆盖）。**自动指派不发指派通知**（告警本体已外发）。
- **端点**（alerts 组）：`GET /alerts/on-call`（view，返回 items + current）；`POST/PATCH/DELETE /alerts/on-call[/{shift}]`（manage + `admin.audit:ops.alerts,on_call_*`）。
- **config** `alerts.on_call.enabled`（`OPS_ALERT_ON_CALL_ENABLED`，默认 false，opt-in）。

### 2. 指派通知推送

- **`AlertNotificationService::sendAssignment(OpsAlert, string $assignee)`**：复用 `sendTest` 的内存态 `OpsAlert`（severity=info、source=alert-assignment、不落库）+ 逐通道 `dispatch()`，经现有 guard/severity 矩阵（info 行）路由。config 未开→no-op。
- **hook**：`AlertCenterService::assign()`（手动指派）在 `markAssigned` 后调用；自动指派路径**不调用**。
- **config** `alerts.assignment_notify.enabled`（`OPS_ALERT_ASSIGNMENT_NOTIFY_ENABLED`，默认 false，opt-in）。

### 3. 每通道独立模板

- **`formatMessage(OpsAlert, string $channel)`**：回退链 `message_template_{channel}` → 全局 `message_template` → 内置；`strtr` 占位符不变（`{title}{severity}{source}{status}{time}{message}`）。4 个文本通道调用点传各自通道名；**webhook 保持结构化** `webhookPayload`（不套模板）。boot-safe。
- **设置四处同步**（仅文本通道 telegram/mail/dingtalk/feishu，webhook 除外，`OpsAlertSetting::textChannels()` 统一）：`defaults()` per-channel key；`updateSettings()` per-channel array_key_exists 守卫；`AlertSettingsUpdateRequest` per-channel nullable 规则；config 每通道块加 `message_template` env。
- 前端：设置面板「每通道模板」`el-collapse`，各文本通道一个 textarea（留空=回退全局）。

### 4. SLA 达标率

- **`AlertCenterService::slaCompliance($pairs, $targetKey)`**：按严重级统计实际时长 `<=` 该级目标（`slaTargets` 分钟*60 秒）的比例 → 每级 `{within, total, rate}`（`rate=total>0?round(within/total*100):null`）+ `overall`。
- **`slaSummary()`** 加 `compliance.ack`（vs ack_minutes）+ `compliance.resolve`（vs resolve_minutes）；端点 `AlertController::sla()` 直接 spread，无需改控制器。
- 前端 `AlertSla.vue`：「SLA 达标率」区，ack/resolve 两组按严重级 `el-progress`（≥90% 绿 / ≥60% 黄 / 其余红）+ within/total + 总体率。

## 权限 / 安全边界

- 值班/指派通知均 **opt-in**（config/env 默认关，部署期设）；值班自动指派只对规则引擎新建、未指派告警；值班人自由串（不引 FK）；boot-safe（表缺失→currentOnCall null，不阻断告警）。
- 指派通知复用 send 的 enabled/矩阵门（severity=info）、内存态不落库、自动指派不发（避免双重通知）。
- 每通道模板仅文本通道，回退链 per-channel→全局→内置，只 strtr + 原脱敏截断，webhook 保持结构化。
- SLA 达标率纯只读聚合，空数据 rate=null 不除零，目标走 config（同 phase-30）。
- on-call 管理走 `ops.alerts.manage` + 审计；`PhaseFiveSecurityTest` 覆盖（POST 路由挂 audit + 加入 permissionProtectedEndpoints）。

## 新增 env

```
OPS_ALERT_ON_CALL_ENABLED=false
OPS_ALERT_ASSIGNMENT_NOTIFY_ENABLED=false
OPS_ALERT_TELEGRAM_MESSAGE_TEMPLATE=      # 各文本通道可选专属模板
OPS_ALERT_MAIL_MESSAGE_TEMPLATE=
OPS_ALERT_DINGTALK_MESSAGE_TEMPLATE=
OPS_ALERT_FEISHU_MESSAGE_TEMPLATE=
```

## 不做

- 值班周期性轮转（daily/weekly 自动排班——本阶段用绝对时间段窗口）、值班人 FK/在线状态、指派通知每通道独立开关、SLA 达标率的按人/历史趋势分解。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseThirtyFourOnCall|PhaseThirtyFourAssignNotify|PhaseThirtyFourPerChannelTemplate|PhaseThirtyFourSlaCompliance|PhaseTwentyEightAlertSla|PhaseFiveSecurity'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
# 手动：建当前时段值班班次→OPS_ALERT_ON_CALL_ENABLED=true→触发规则告警→自动指派给值班人；
#       OPS_ALERT_ASSIGNMENT_NOTIFY_ENABLED=true→手动指派收到通知；设 telegram 专属模板看文案；SLA 页看达标率。
```

## 交付物

- 后端：迁移(ops_on_call_shifts) + `OpsOnCallShift` + `OnCallRotationService` + `OnCallController` + `OnCallShiftStoreRequest` + 4 路由 + `AlertCenterService`(markAssigned/自动指派 hook/slaCompliance/构造注入) + config(on_call/assignment_notify) + `AlertNotificationService`(sendAssignment + formatMessage 通道化) + per-channel 模板四处同步 + config。
- 前端：`opsStage4.ts`（类型/函数）+ `OnCallSchedule.vue` + 路由/侧边栏 + `AlertCenter.vue`(每通道模板) + `AlertSla.vue`(达标率)。
- 测试：`PhaseThirtyFourOnCall/AssignNotify/PerChannelTemplate/SlaCompliance` + SLA/PhaseFive 扩展。
