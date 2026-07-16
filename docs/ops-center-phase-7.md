# Ops Center 第七阶段：告警规则配置后台

## 实现范围

- 新增告警规则配置表 `ops_alert_rules`。
- 告警规则只允许系统白名单 key，不允许页面创建任意表达式或任意指标。
- 规则管理沿用现有权限：
  - 查看规则：`ops.alerts.view`
  - 修改阈值与启停：`ops.alerts.manage`
- 规则修改与启停写入审计：
  - `ops.alerts / rule_update`
  - `ops.alerts / rule_toggle`
- `AlertRuleEngineService` 优先读取启用的数据库规则。
- 数据库规则不存在时回退既有 `config('ops.alerts.thresholds.*')` 默认值。
- 数据库规则存在但被禁用时，不触发该规则对应的新告警。
- 已有 `ops_alerts` 历史告警不自动删除，仍按确认、恢复和审计流程保留。

## 默认规则白名单

| Key | Source | Metric | Unit | 说明 |
| --- | --- | --- | --- | --- |
| `disk_usage` | `disk` | `usage_percent` | `%` | 磁盘挂载点使用率 |
| `queue_pending` | `queue` | `pending_jobs` | `jobs` | 队列待处理任务数 |
| `failed_jobs` | `queue` | `failed_jobs` | `jobs` | 失败队列任务数 |
| `docker_unhealthy` | `docker` | `unhealthy_count` | `containers` | unhealthy 容器数 |
| `docker_exited` | `docker` | `exited_count` | `containers` | exited 容器数 |
| `network_mbps` | `network` | `mbps` | `MB/s` | 网络收发峰值 |

## API

```text
GET  /api/ops/alerts/rules
PUT  /api/ops/alerts/rules/{adminRule}
POST /api/ops/alerts/rules/{adminRule}/toggle
```

`PUT` 请求只允许提交：

```json
{
  "warning_threshold": 80,
  "critical_threshold": 95,
  "is_active": true
}
```

禁止提交 `key`、`name`、`source`、`metric`、`operator`、`unit` 等系统字段。

## 参数边界

- 磁盘百分比：`0` 到 `100`。
- 队列、失败任务、Docker 计数：`0` 到 `1000000`。
- 网络吞吐：`0` 到 `100000`。
- `critical_threshold` 非空时必须大于或等于 `warning_threshold`。
- Docker exited 等单阈值规则允许 `critical_threshold=null`。

## 前端

- 告警中心页面新增“规则配置”区域。
- 支持查看规则名称、来源、指标、单位、说明、启用状态和阈值。
- 支持保存阈值、启停规则、保存中禁用和错误提示。
- 不展示 Telegram token、邮件收件人或其他敏感通知配置。

## 验收命令

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

## 发布与回滚

- 发布前执行 migration 创建 `ops_alert_rules`。
- 首次访问规则列表会同步系统默认规则。
- 回滚代码前如已执行 migration，数据库表不会自动删除，除非执行 Laravel migration rollback。
- 回滚后告警引擎恢复使用配置文件阈值。
- 禁用规则不会清理历史告警；如需关闭历史告警，仍使用现有确认/恢复流程。
