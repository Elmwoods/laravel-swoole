# Ops Center 第七阶段发布验收：告警规则配置

## 发布前检查

按顺序执行，避免多个 PHPUnit 进程同时刷新同一个 `testing` 数据库：

```bash
git diff --check
npm run build
./vendor/bin/sail artisan route:list --path=ops
./vendor/bin/sail artisan test --filter PhaseFourAlertCenterTest
./vendor/bin/sail artisan test --filter AlertRuleEngineServiceTest
./vendor/bin/sail artisan test
```

静态检查：

```bash
rg -n "v-html|dangerouslyUseHTMLString|innerHTML|insertAdjacentHTML" resources/js/pages/ops/AlertCenter.vue resources/js/api/opsStage4.ts
rg -n "console\\.error\\(error\\)" resources/js
```

## 发布顺序

1. 执行数据库迁移：

```bash
./vendor/bin/sail artisan migrate
```

2. 确认后台权限已准备：

- `super_admin` 默认具备全部权限。
- `ops_admin` 应具备 `ops.alerts.view` 和 `ops.alerts.manage`。
- 只读账号只有 `ops.alerts.view` 时，可以查看规则，但不能修改阈值或启停。

3. 初始化默认规则。任选其一：

```bash
./vendor/bin/sail artisan ops:alerts:evaluate
```

或登录后台访问：

```text
/admin/ops/alerts
```

`AlertRuleEngineService` 在评估前会同步默认规则，因此 CLI、定时任务和页面都可以完成首次初始化。重复同步只更新系统元数据，不覆盖用户已修改的阈值和 `is_active=false`。

## 接口验收

使用已登录后台会话验证：

```text
GET  /api/ops/alerts/rules
PUT  /api/ops/alerts/rules/disk_usage
POST /api/ops/alerts/rules/disk_usage/toggle
POST /api/ops/alerts/evaluate
```

`PUT` 只允许提交：

```json
{
  "warning_threshold": 80,
  "critical_threshold": 95,
  "is_active": true
}
```

必须验证的失败场景：

- 未登录访问规则接口返回 `401`。
- 无 `ops.alerts.view` 查看规则返回 `403`。
- 有 `ops.alerts.view` 但无 `ops.alerts.manage` 修改或启停返回 `403`。
- 非白名单规则 key 返回 `404`。
- 负数、百分比超过 `100`、网络吞吐超过 `100000`、`critical_threshold < warning_threshold` 返回 `422`。
- 提交 `key`、`name`、`source`、`metric`、`operator`、`unit` 等系统字段返回 `422`。

## 审计验收

在“安全管理 / 审计日志”中筛选：

- `module=ops.alerts`
- `action=rule_update`
- `action=rule_toggle`

需要确认：

- 成功修改或启停记录 `status_code=200`。
- 无权限启停记录 `status_code=403`。
- 验证失败记录 `status_code=422`。
- 非白名单规则 key 记录 `status_code=404`。
- 审计 payload 只包含规则 key、阈值和启停摘要，不包含通知 token、Cookie、Authorization 或告警正文。

## 定时任务验收

`routes/console.php` 中的 `ops:alerts:evaluate` 会按现有调度执行。发布后建议人工执行一次：

```bash
./vendor/bin/sail artisan ops:alerts:evaluate
```

预期输出包含检测数量，例如：

```text
Ops alerts evaluated, detected: 0
```

若某条规则被禁用，定时评估不会再为该规则创建新的告警；已有历史告警不会被删除，仍按确认和恢复流程处理。

## 回滚说明

- 回滚代码不会自动删除 `ops_alert_rules` 表；如需移除表，执行对应 migration rollback。
- 回滚后旧代码会继续使用 `config('ops.alerts.thresholds.*')`。
- 已禁用规则不会自动恢复启用；如需恢复，回滚前先在页面启用或在数据库中手动修正。
- 历史 `ops_alerts` 不自动清理，避免丢失审计与值班排障线索。
