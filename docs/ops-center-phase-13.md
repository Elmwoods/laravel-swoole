# Ops Center 第十三阶段：趋势可观测性 + 审计筛选/导出增强

## 范围

两条线并行：

1. **趋势可观测性**
   - 巡检与告警新增按天聚合的趋势接口，前端在现有页面内嵌 echarts 趋势卡片。
   - 给增长最快的 `ops_alert_evaluations`（每分钟一条）补每日保留清理。
2. **审计筛选/导出增强**
   - 审计日志新增 facets 下拉、关键词搜索、快捷时间段。
   - 审计导出改为流式导出全部匹配（去掉 5000 行截断），并补 `admin_name` / `message` 列。

**明确不做**：服务端保存的自定义筛选预设；系统/Redis 指标长期持久化；`ops_release_checks` 保留清理（第十阶段已定“永久保留”）。

## 接口

```text
GET /api/ops/inspections/trend?days=N     # 权限 ops.inspections.view
GET /api/ops/alerts/trend?days=N          # 权限 ops.alerts.view
GET /api/admin/audit-logs/facets          # 权限 admin.audit.view
```

- 两个 trend 接口 `days` 归一化到 1–90（默认 14），返回 `{days, buckets:[{date, ...}]}`，按天零填充连续日期。
  - 巡检桶：`pass / warn / fail / total / avg_duration_ms`。
  - 告警桶：`evaluations / detected / auto_resolved / avg_duration_ms`。
- `facets` 返回 `{modules, actions, results}` 去重升序列表。
- 审计 `index` / `export` 新增 `keyword` 参数（LIKE 命中 `admin_email` / `message` / `module` / `action`）。

## 命令与调度

```text
ops:alerts:prune-evaluations {--days=30} {--dry-run}   # 每日 03:20
```

## 安全边界

- 趋势聚合用 `DATE(created_at)` + `SUM(CASE WHEN ...)`，SQLite / MySQL 通用，只读已持久化的历史表，不引入新的敏感数据面。
- 审计导出仍复用 `AdminCsvExportService`（`= + - @` 公式注入转义 + 安全文件名）与 `AdminAuditService::sanitizePayload`（敏感键脱敏）；新增 `message` 列同样经转义。
- 流式导出用 `lazy()` 游标，设 100000 行硬顶防止极端情况下 OOM。
- 关键词走参数绑定的 LIKE，无 SQL 注入；facets 只暴露列的去重枚举值，不含敏感内容。
- 前端趋势图与筛选均为文本/图表渲染，无 HTML 注入。

## 验收命令

```bash
git diff --check
npm run build
./vendor/bin/sail artisan route:list --path=ops/inspections/trend
./vendor/bin/sail artisan route:list --path=admin/audit-logs
./vendor/bin/sail artisan schedule:list
./vendor/bin/sail artisan test --filter PhaseThirteenTrendTest
./vendor/bin/sail artisan test --filter PhaseThirteenAuditTest
./vendor/bin/sail artisan test --filter OpsAlertEvaluationPrune
./vendor/bin/sail artisan test --filter PhaseFiveSecurityTest
./vendor/bin/sail artisan test
```

手动验证：

```bash
./vendor/bin/sail artisan ops:alerts:prune-evaluations --dry-run
# 登录后在「自动巡检」「告警中心」页查看趋势卡片随 7/14/30 天切换刷新
# 审计日志页使用 模块/动作 下拉、关键词、快捷时间段，并导出 CSV（全量匹配）
```

静态扫描：

```bash
rg -n "v-html|dangerouslyUseHTMLString|innerHTML|insertAdjacentHTML" \
  resources/js/pages/ops/Inspection.vue resources/js/pages/ops/AlertCenter.vue resources/js/pages/admin/AdminAuditLogs.vue
```
