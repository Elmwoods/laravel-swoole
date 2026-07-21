# Ops Center 第十五阶段：系统指标长期持久化 + 多天趋势

## 范围

- 系统指标从「仅 Redis 缓存最近 60 采样」升级为**分钟级持久化 + 按天聚合的多天趋势**。
- 只做系统指标（CPU 负载 / load1 / 内存使用率 / swap 使用率）；Redis 指标与 network 速率持久化留作后续。
- 实时监控仍走既有 Redis/WebSocket 图，不受影响。

## 持久化与聚合策略

- 定时命令 `ops:metrics:persist` 每分钟用 `SystemMetricsCollector::collect()` 采一个点，派生 4 个标量写入 `ops_metric_samples`（约 1440 条/天）：
  - `cpu_load` = load[0]、`load1`
  - `memory_used_percent` = (total-available)/total*100
  - `swap_used_percent` = (total-free)/total*100（total=0 记 0）
- 采集失败（如容器 `/proc` 不可读）时命令 warn + 退出 0，不让调度器写 ERROR。
- 趋势按 `DATE(captured_at)` 分桶求各指标平均（SQLite/MySQL 通用），只返回有数据的天、升序。
- 保留清理 `ops:metrics:prune` 每日 03:30 删除超过保留期（默认 30 天）的采样。

## 接口

```text
GET /api/ops/system/metrics-trend?days=N   # 权限 ops.system.view
```

- `days` 归一化 1–90，默认 14；返回 `{days, buckets:[{date, cpu_load, load1, memory_used_percent, swap_used_percent}]}`。

## 命令与调度

```text
ops:metrics:persist                     # 每分钟
ops:metrics:prune {--days=30} {--dry-run}   # 每日 03:30
```

## 安全边界

- 趋势聚合只读已持久化的系统标量，不含敏感信息；采样表不存进程/命令/日志正文。
- 采集命令容错，避免「命令失败 → 日志 ERROR → 被 watch-errors 采集成新告警」的自澎环。
- 前端趋势页文本/图表渲染，无 HTML 注入。

## 验收命令

```bash
git diff --check
npm run build
./vendor/bin/sail artisan route:list --path=ops/system
./vendor/bin/sail artisan schedule:list
./vendor/bin/sail artisan test --filter OpsMetricSampleServiceTest
./vendor/bin/sail artisan test --filter PersistMetricsCommandTest
./vendor/bin/sail artisan test --filter PruneMetricSamplesCommandTest
./vendor/bin/sail artisan test --filter MetricsTrendEndpointTest
./vendor/bin/sail artisan test
```

手动验证：

```bash
./vendor/bin/sail artisan ops:metrics:persist       # 写入一行（容器有 /proc）
./vendor/bin/sail artisan ops:metrics:prune --dry-run
# 登录后访问 /admin/ops/system/trend，折线随 7/14/30 天切换刷新
```

静态扫描：

```bash
rg -n "v-html|dangerouslyUseHTMLString|innerHTML|insertAdjacentHTML" resources/js/pages/ops/system/SystemTrend.vue resources/js/api/opsSystemTrend.ts
```
