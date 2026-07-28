# Ops Center Phase 37 — 告警运营洞察四合一

抖动检测 + 处理备注协作 + 处理预案 runbook + 告警热力图。

## 背景

phase 36 有了批量操作、关联抑制、上岗提醒、周报。本阶段补「降噪 + 协作 + 处置指引 + 洞察」。**顺带**给告警加了**详情抽屉**（此前无，且 `timeline` 字段已 serialize 却从未渲染）——备注/预案/时间线都进这个抽屉。

## 范围

### 1. 告警抖动检测（flapping）

- **迁移** `ops_alerts.flap_count`(int) + `flapping_until`(nullable)。**config** `alerts.flapping`（enabled 默认 false + window_minutes 30 + threshold 3 + cooldown_minutes 30）。
- **`storeAlert()`**：`fill()` 前捕获 `getOriginal('status')==='resolved'` = reopen；save 后 `registerReopen()`。
- **`registerReopen()`**：记 `reopened` 事件；统计窗口内 `reopened` 次数 → `flap_count`；`enabled && count>=threshold` → `flapping_until=now+cooldown`。
- **`AlertNotificationService::send()`** 加第三道 gate（silence/suppressed 之后）：`flapping.enabled && flapping_until->isFuture()` → reason=flapping（不 dispatch，仍入库/广播）。冷却期过自动恢复外发。
- `serialize()` 暴露 `flap_count`/`flapping_until`；前端「抖动中」tag。

### 2. 告警处理备注 / 协作

- **迁移** `create_ops_alert_notes_table`（alert_id FK cascade + admin_user_id + author + body）。模型 `OpsAlertNote` + 服务 `OpsAlertNoteService`（list/add/**仅作者可删**，owner 在 WHERE）。
- **控制器** `AlertNoteController`（author 用 **`$request->user('admin')`**，非写死 `ops-user`）+ 请求 `AlertNoteStoreRequest`（body required max2000）。
- **路由** `GET /{alert}/notes`（view）；`POST /{alert}/notes`（manage + `note_create` 审计）；`DELETE /{alert}/notes/{note}`（manage + `note_delete` 审计）。与事件表分离（不污染 SLA 配对）。
- 前端详情抽屉：备注列表 + `canManage` 时加备注 textarea + 作者可删。

### 3. 告警处理预案 runbook

- **config** `alerts.runbooks`（`source => {url, steps:[]}` 部署期设）。
- **`AlertCenterService::runbookFor($source)`**（读 config 规范化，无则 null）；`serialize()` 加 `runbook`——所有告警（任何来源）自动透出。
- 前端详情抽屉：预案 url 链接 + steps 有序列表（只读）。

### 4. 告警热力图

- **`AlertCenterService::heatmapSummary($days,$weighted)`**：PHP 按 `created_at->dayOfWeek(0-6)×->hour(0-23)` 分 7×24 桶（DB 可移植，不用 SQL HOUR/DAYOFWEEK）+ 最吵来源 top5 + 按天趋势；weighted 时按 hit_count 加权。
- **端点** `GET /alerts/heatmap`（view）。
- 前端 `AlertHeatmap.vue` 新页：echarts `heatmap` + `visualMap`（小时×星期）+ 最吵来源表 + 每日趋势折线；7/30/90 天 + 刷新。侧边栏「告警热力图」。

## 权限 / 安全边界

- 抖动 opt-in、reopen 只算 `resolved→open`、两点 gate（storeAlert 标 + send 读取抑制）、冷却过自动恢复、行内读取不查库。
- 备注 author 用真实登录管理员、仅作者可删、读 view/写 manage + 审计、与事件表分离。
- 预案纯 config 只读、按 source 解析、无则 null、不执行步骤。
- 热力图纯只读聚合、PHP 分桶、按 created_at、默认不加权、复用 `ops.alerts.view`。

## 新增 env

```
OPS_ALERT_FLAPPING_ENABLED=false
OPS_ALERT_FLAPPING_WINDOW_MINUTES=30
OPS_ALERT_FLAPPING_THRESHOLD=3
OPS_ALERT_FLAPPING_COOLDOWN_MINUTES=30
# 预案在 config/ops.php alerts.runbooks（source => {url,steps}）
```

## 不做

- 抖动的自动 resolve 抖动源；备注 @提醒/富文本/附件；预案的 DB 可视化编辑与一键执行；热力图实时刷新/分钟粒度；详情抽屉做成独立路由页。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseThirtySevenFlapping|PhaseThirtySevenNotes|PhaseThirtySevenRunbook|PhaseThirtySevenHeatmap|PhaseFiveSecurity|PhaseTwentyEightAlertSla'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
# 手动：反复 resolve/触发同一告警达阈值标「抖动中」不外发；告警「详情」看时间线/预案/加备注；热力图页看小时×星期分布。
```

## 交付物

- 后端：迁移(flapping 两列 / ops_alert_notes) + `AlertCenterService`(registerReopen/storeAlert reopen/runbookFor/heatmapSummary/serialize) + `AlertNotificationService`(flapping gate) + `OpsAlertNote`/`OpsAlertNoteService`/`AlertNoteController`/`AlertNoteStoreRequest` + `AlertController::heatmap` + config(flapping/runbooks) + 4 路由。
- 前端：`opsStage4.ts`（notes/heatmap/flapping/runbook 类型+函数）+ `AlertCenter.vue`（详情抽屉 + 抖动 tag）+ `AlertHeatmap.vue` + 路由/侧边栏。
- 测试：`PhaseThirtySevenFlapping/Notes/Runbook/Heatmap` + PhaseFive 追加。
