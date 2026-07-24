# Ops Center Phase 28 — 告警处理 SLA 统计（MTTA / MTTR）

## 范围

告警中心能看单条告警的生命周期，但没有整体**处理效率**度量。本阶段加一个**只读 SLA 统计**：
从告警生命周期事件聚合 MTTA（平均确认时长）/ MTTR（平均恢复时长），按来源与严重级分解、按天趋势、
当前 open 告警按拖延时长分桶。纯聚合、只读，不改任何生命周期逻辑。

## MTTA / MTTR 口径（关键）

- **起点锚 = `ops_alerts.created_at`**（唯一可靠）：`storeAlert` 用 `firstOrNew(fingerprint)`，re-fire 复用旧行 →
  `created_at` 保留首次发生时间；`last_seen_at`/`updated_at`/`status` 在 re-fire 时会变，不可用。
- **确认/恢复时间 = 配对 `ops_alert_events` 事件的 `created_at`**（比列干净）：`ops_alerts` **没有 `resolved_at` 列**，
  `acknowledged_at` 列在「未确认直接 resolve」时被回填成 resolve 时间，不可信。
  - **MTTA** = 首个 `action='acknowledged'` 事件.created_at − alert.created_at。
  - **MTTR** = 首个恢复事件.created_at − alert.created_at，恢复事件 = `resolved` / `auto_resolved` /
    `channel_health_recovered` / `inspection_recovered`。
- 每个告警取窗口内**最早**的匹配事件；时长在 **PHP 计算**（`max(0, at−born)` 秒），避免 sqlite/mysql 时间差 SQL 不可移植。
- 窗口按**完成事件日期**（`event.created_at >= now−days`）：统计「近 N 天内被确认/恢复的告警」；未恢复的不进 MTTR。

## 返回结构

`GET /api/ops/alerts/sla?days=N`（权限 `ops.alerts.view`，默认 30，范围 1–90）：

```
{ days, window_days, generated_at,
  mtta:{count, avg_seconds, max_seconds},
  mttr:{count, avg_seconds, max_seconds},
  by_source:[{source, mtta_avg_seconds, mtta_count, mttr_avg_seconds, mttr_count}](按 MTTR 降序),
  by_severity:{critical:{mttr_avg_seconds,mttr_count}, warning:{…}, info:{…}},
  trend:[{date, mttr_avg_seconds, resolved_count}](按恢复事件日期分桶),
  open_aging:{under_1h, one_to_24h, over_24h}(当前 status=open 按 now−created_at) }
```

## 前端

独立页「告警 SLA」（`resources/js/pages/ops/AlertSla.vue`，侧边栏「告警 SLA」，镜像趋势页）：
- days `el-radio-group`（7/30/90）+ 刷新。
- 统计卡：MTTA 平均、MTTR 平均、已恢复数、当前积压（>24h 标红）。时长 `formatDuration`（`X小时Y分`/`X分Y秒`）。
- 分项：按来源 `el-table`、按严重级 MTTR tag、open_aging 三桶。
- MTTR 趋势 `echarts` 折线（y 轴分钟）。纯文本、无 `v-html`。

## 覆盖面说明

核心采集源（disk/queue/docker/… 托管自动恢复源）升起时不记 triggered 事件，但它们的 `created_at`（起点）
与 `auto_resolved`/手动 `resolved` 恢复事件都在，所以 MTTA/MTTR 对所有源都可算。

## 安全边界 / 不做

- **纯只读聚合**：只 SELECT `ops_alerts`/`ops_alert_events`，权限复用 `ops.alerts.view`（不新增 slug）；不出正文。
- 起点固定 `created_at`、确认/恢复取事件 `created_at`（不信被回填的 `acknowledged_at` / 不稳定的 `updated_at`）。
- **不做**：SLA 阈值/达标率告警（超 SLA 自动升警）、按值班人/分派人分解、自定义任意时间窗、p50/p90 百分位（先做均值+max）。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseTwentyEightAlertSla'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
rg -n "v-html|innerHTML" resources/js/pages/ops/AlertSla.vue
# 手动：确认/恢复几条告警 → /admin/ops/alerts-sla 看 MTTA/MTTR 卡、来源分解、趋势、积压分桶随 days 刷新。
```
