# Ops Center 第十六阶段：Redis 指标长期持久化 + 多天趋势

## 范围

- 延续 phase 15，把 **Redis 指标**也从「仅 Cache 最近 60 采样」升级为**分钟级持久化 + 按天聚合的多天趋势**。
- 指标：`ops`（instantaneous_ops_per_sec）、`clients`（connected_clients）、`memory_mb`（used_memory 换算）、`hit_rate`（keyspace 命中率 %）。
- 实时监控仍走既有 Cache 轮询图（`RedisChart.vue`），不受影响。

## 持久化与聚合策略

- 定时命令 `ops:redis-metrics:persist` 每分钟合并 `RedisMetricsService::collect()` + `RedisMonitorService::getHitRate()`，派生 4 个标量写入 `ops_redis_metric_samples`。
- 采集失败（如 Redis 不可用）时 warn + 退出 0，不让调度器写 ERROR。
- 趋势按 `DATE(captured_at)` 分桶求各指标平均（SQLite/MySQL 通用），只返回有数据的天、升序。
- 保留清理 `ops:redis-metrics:prune` 每日 03:40 删除超过保留期（默认 30 天）的采样。
- `--demo=N` 回填 N 天确定性 demo 样本（仅非生产），用于本地观察趋势。

## 接口

```text
GET /api/ops/redis-metrics/trend?days=N   # 权限 ops.system.view
```

- `days` 归一化 1–90，默认 14；返回 `{days, buckets:[{date, ops, clients, memory_mb, hit_rate}]}`。

## 命令与调度

```text
ops:redis-metrics:persist                     # 每分钟（真实采集）
ops:redis-metrics:persist --demo=14           # 回填 14 天 demo（仅非生产）
ops:redis-metrics:prune {--days=30} {--dry-run}   # 每日 03:40
```

## 安全边界

- 趋势只读已持久化的 Redis 标量，不含敏感信息。
- 采集命令容错，避免「命令失败 → 日志 ERROR → 被 watch-errors 采集成新告警」的自澎环。
- 前端趋势页文本/图表渲染，无 HTML 注入。

## 验收命令

```bash
git diff --check
npm run build
./vendor/bin/sail artisan route:list --path=ops/redis-metrics
./vendor/bin/sail artisan schedule:list
./vendor/bin/sail artisan test --filter OpsRedisMetricSampleServiceTest
./vendor/bin/sail artisan test --filter PersistRedisMetricsCommandTest
./vendor/bin/sail artisan test --filter PruneRedisMetricSamplesCommandTest
./vendor/bin/sail artisan test --filter RedisMetricsTrendEndpointTest
./vendor/bin/sail artisan test
```

手动验证：

```bash
./vendor/bin/sail artisan ops:redis-metrics:persist            # 写入一行
./vendor/bin/sail artisan ops:redis-metrics:persist --demo=14  # 回填 14 天
# 登录后访问 /admin/ops/redis/trend，折线随 7/14/30 天切换刷新
./vendor/bin/sail artisan ops:redis-metrics:prune --dry-run
```

静态扫描：

```bash
rg -n "v-html|dangerouslyUseHTMLString|innerHTML|insertAdjacentHTML" resources/js/pages/ops/RedisTrend.vue resources/js/api/opsRedisTrend.ts
```
