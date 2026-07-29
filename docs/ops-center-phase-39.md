# Ops Center Phase 39 — 告警生态四合一

入站 Webhook 告警 + 规则变更历史 + 值班绩效统计 + 服务依赖拓扑图。

## 背景

phase 38 有了标签、相似告警、交接班、Prometheus 导出。本阶段补「外部接入 + 规则治理 + 绩效 + 可视化根因」。

## 范围

### 1. 入站 Webhook 告警

- **config** `alerts.ingest`（`enabled` 默认 false + `token`）。**`AlertCenterService::ingestExternal($payload)`**（照 `raiseAuditAnomalyAlert`）：`AlertDTO` → storeAlert（复用去重/自动标签/关联抑制/值班自动指派）→ 条件通知 → `ingested` 事件 → 广播；`dedup_key` → `context['target']` 稳定去重；显式 `tags` 合并。
- **控制器** `AlertIngestController`（照 `MetricsController` token 守卫，不走会话）；**路由** `POST /api/ingest/alerts` **注册在 `api/ops/` 前缀之外**——避免 `PhaseFiveSecurity` 的「api/ops/ POST 必挂 admin.audit」规则（token-only 不该挂 admin.audit）。请求 `AlertIngestRequest`（source 白名单/severity 枚举/title/message/context/tags/dedup_key）。
- 无前端（外部调用）；文档给 curl。

### 2. 规则变更历史

- **迁移** `create_ops_alert_rule_changes_table`（照 `ops_alert_events`，timestamps=false）：rule_key/field/old_value/new_value/actor/created_at。模型 `OpsAlertRuleChange` + 服务 `AlertRuleChangeService`（`record` 字段级 diff，仅变化字段写行；`history(?key)`）。
- **hook**：`AlertRuleController::update/toggle`（forceFill 前捕获旧值 + save 后 record）；`AlertRuleRegistryService::import($rules, $actor)`（透传 actor，每 applied 行 record）。与既有 `admin.audit:ops.alerts,rule_*` 并存（后者记 who/when/result，缺旧值/字段级）。
- **端点** `GET /alerts/rules/changes?key=`（view）。前端：规则面板「变更历史」dialog。

### 3. 值班绩效统计

- **`AlertCenterService::workloadSummary($days)`**：私有 `workloadPairs`（`slaPairs` + `e.actor`/`a.assigned_to`）；ack + resolve 配对按 **事件 actor** 分组，**排除系统合成 actor**（`ops-` 前缀 / `on-call-auto` / `external` / 空）；每人确认/恢复数 + 平均时长（`durationStats`）+ 被指派数。
- **端点** `GET /alerts/workload?days=`（view）。前端 `Workload.vue` 页（处理人表）+ 侧边栏「值班绩效」。

### 4. 服务依赖拓扑图

- **`AlertCorrelationService::topology()`**：nodes=deps 的 key∪value 各来源 + 自查 open 计数（不注入 AlertCenterService 防环）；edges=parent→child；firing=open>0、suppressed=firing && `firingParents` 非空。只读、boot-safe。
- **端点** `GET /alerts/topology`（view）。前端 `AlertTopology.vue`（echarts `graph` force 有向图，节点色 firing 红/suppressed 橙/正常绿）+ 侧边栏「依赖拓扑」。

## 权限 / 安全边界

- 入站 token `hash_equals` + opt-in + **组外注册**（不违反 PhaseFive 审计-POST 规则）+ 复用 storeAlert 全部安全处理；source 白名单、severity 枚举。
- 变更历史字段级 diff（旧值 forceFill 前捕获，中间件做不到）+ 与 admin.audit 并存；只读 view。
- 绩效纯只读聚合、归因用事件 actor 且排除系统 actor、时长 PHP 计算（可移植）。
- 拓扑纯只读、自查 open 计数防环、config 依赖。

## 新增 env

```
OPS_ALERT_INGEST_ENABLED=false
OPS_ALERT_INGEST_TOKEN=<随机 token>
```

curl 示例：
```
curl -XPOST 'https://<host>/api/ingest/alerts?token=<token>' -H 'Content-Type: application/json' \
  -d '{"source":"external","severity":"warning","title":"CI failed","message":"pipeline #42","dedup_key":"ci:42","tags":["team:ci"]}'
```

## 不做

- 入站签名校验/速率细化、字段映射 DSL；变更历史回滚/审批；绩效 KPI 打分；拓扑自动依赖发现与拖拽编辑。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseThirtyNineIngest|PhaseThirtyNineRuleHistory|PhaseThirtyNineWorkload|PhaseThirtyNineTopology|PhaseFiveSecurity'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
# 手动：OPS_ALERT_INGEST_ENABLED=true+token → curl 入站 → 告警中心出现；改规则阈值 → 变更历史 dialog；值班绩效页看人均；配 correlation.dependencies → 拓扑图看依赖 + firing 高亮。
```

## 交付物

- 后端：config(ingest) + `AlertCenterService`(ingestExternal/workloadSummary/workloadPairs) + `AlertIngestController`/`AlertIngestRequest` + `/ingest/alerts` 组外路由；迁移(ops_alert_rule_changes) + `OpsAlertRuleChange`/`AlertRuleChangeService` + `AlertRuleController`(hook + changes) + `AlertRuleRegistryService::import` 透传 actor；`AlertController`(workload/topology) + `AlertCorrelationService::topology` + 路由。
- 前端：`opsStage4.ts`（3 组类型+函数）+ `AlertCenter.vue`（变更历史 dialog）+ `Workload.vue` + `AlertTopology.vue` + 路由/侧边栏。
- 测试：`PhaseThirtyNineIngest/RuleHistory/Workload/Topology`。
