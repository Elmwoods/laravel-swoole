# Ops Center Phase 38 — 告警运营集成四合一

告警标签 + 相似告警关联 + 值班交接班记录 + Prometheus 指标导出。

## 背景

phase 37 有了抖动检测、备注、预案、热力图。本阶段补「分类 + 经验复用 + 交接闭环 + 外部集成」。

## 范围

### 1. 告警标签系统

- **迁移** `ops_alerts.tags`(json，cast array)。**config** `alerts.auto_tags`（`source => [tag,...]` 部署期设）。
- **`storeAlert()`**：`save()` 前按来源自动打标（合并已有标签、去重）。**`setTags()`**：覆盖标签 + 记 `tags_updated` 事件。
- **`paginate()`** 加 `whereJsonContains('tags',$tag)` 过滤（**已在 sqlite 测试确认 Laravel 13 SQLite grammar 支持**）；`AlertIndexRequest` 加 `tag`（白名单正则）；`serialize()` 暴露 `tags`。
- **端点** `POST /{alert}/tags`（manage + `tags_update` 审计）+ `AlertTagsRequest`。前端：告警表标签展示 + 标签筛选 + 详情抽屉标签编辑。

### 2. 相似告警关联

- **`AlertCenterService::similarAlerts($alert)`**（注入 `OpsAlertNoteService`）：同来源 + `status='resolved'` + 非自身，按 `updated_at` 倒序 limit 5，每条附恢复人/恢复备注 + 处理备注（phase37 notes）。
- **端点** `GET /{alert}/similar`（view，只读）。前端：详情抽屉「相似告警」区（同源已恢复 + 备注）。
- 注：同指纹=同一行（re-fire 复用），故相似=其它同源已恢复告警。

### 3. 值班交接班记录

- **迁移** `create_ops_shift_handovers_table`（from_assignee/to_assignee/note/open_alert_count/created_by）。模型 `OpsShiftHandover` + 服务 `ShiftHandoverService`（全局记录，非 owner-scoped）。
- **`create()`**：`to` 缺省取 `currentOnCall()`（无则 422）、`from` 缺省取上一条 `to_assignee`、`open_alert_count` 取 `summary()['open_total']` 快照。`list()` 倒序。
- **端点** `GET /handovers`（view）、`POST /handovers`（manage + `handover_create` 审计）。前端 `ShiftHandover.vue` 页（列表 + 记录交接 dialog）+ 侧边栏「值班交接」。

### 4. Prometheus 指标导出

- **config** `alerts.metrics`（`enabled` 默认 false + `token`）。**服务** `OpsMetricsExporter::render()`：从 `summary()`/`slaSummary(7)`/`notificationStatus()`/`currentOnCall()`/`SecurityOverviewService::overview()` 取标量，拼 Prometheus exposition 文本（`ops_alerts_open{severity}`、`ops_sla_*`、`ops_channel_*`、`ops_on_call_present`、`ops_security_*`）。boot-safe（各源独立 try/catch）。
- **控制器** `MetricsController`（不用 ApiResponse，返回原始 `text/plain; version=0.0.4`）：`!enabled`→404、token（`?token=`/Bearer）`hash_equals` 不符→401。
- **路由** `GET /api/ops/metrics` **注册在会话组之外**（scraper 无会话，token 守卫）。无前端（外部 Grafana/Prometheus 抓取）。

## 权限 / 安全边界

- 标签：自动标 config、手动改标 manage + 审计、筛选白名单正则、`whereJsonContains` 已测。
- 相似告警纯只读（同源已恢复 + 备注）、limit N。
- 交接班全局记录、快照当时 open 数、manage 写 + 审计、无当前值班且未传接手人 422。
- metrics：token `hash_equals` + opt-in（默认 false）+ 只出标量（跳过高基数 by_source/trend/top_ips）+ boot-safe + 组外无会话；GET 无 admin.permission → `PhaseFiveSecurity` 三个扫描测试均不触及（无需改）。

## 新增 env

```
OPS_ALERT_METRICS_ENABLED=false
OPS_ALERT_METRICS_TOKEN=<随机 token>
# 自动标签在 config/ops.php alerts.auto_tags（source => [tag]）
```

## 不做

- 标签规则引擎/自动路由通道；相似度算法（只按 source+resolved）；交接班自动生成（只手动记录 + 快照）；metrics push gateway/自定义维度/历史存储。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseThirtyEightTags|PhaseThirtyEightSimilar|PhaseThirtyEightHandover|PhaseThirtyEightMetrics|PhaseFiveSecurity'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
# 手动：配 auto_tags[disk]=[team:infra] → disk 告警带标签、按 tag 筛、详情改标签；详情看相似已恢复告警+备注；交接班页记录；OPS_ALERT_METRICS_ENABLED=true + token → curl /api/ops/metrics?token=xxx。
```

## 交付物

- 后端：迁移(tags 列 / ops_shift_handovers) + `AlertCenterService`(auto-tag/setTags/paginate tag/similarAlerts/serialize) + `AlertTagsRequest` + `AlertController`(setTags/similar) + `OpsShiftHandover`/`ShiftHandoverService`/`ShiftHandoverController`/`ShiftHandoverStoreRequest` + `OpsMetricsExporter` + `MetricsController` + config(auto_tags/metrics) + 路由（tags/similar/handovers/metrics）。
- 前端：`opsStage4.ts`（tags/similar/handover 类型+函数）+ `AlertCenter.vue`（标签 + 相似告警）+ `ShiftHandover.vue` + 路由/侧边栏。
- 测试：`PhaseThirtyEightTags/Similar/Handover/Metrics` + PhaseFive 追加。
