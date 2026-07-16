# Ops Center 第八阶段：告警运营闭环增强

## 实现范围

- 告警巡检状态：记录 `ops:alerts:evaluate` 和手动评估的最近执行状态、耗时、命中数和失败摘要。
- 告警处理流：支持告警指派负责人，并为指派、确认、恢复、自动恢复写入时间线。
- 内置规则扩展：在既有磁盘、队列、Docker、网络规则上，新增 CPU、内存、Redis、MySQL、Octane、Supervisor 规则。
- 通知策略配置：页面可配置重复通知间隔、自动恢复宽限、通道总开关和严重级别通道矩阵。
- 不新增 RBAC 权限点，继续使用：
  - 查看：`ops.alerts.view`
  - 管理：`ops.alerts.manage`

## 数据表

- `ops_alert_evaluations`：保存评估运行状态。失败信息只保存脱敏摘要，不保存堆栈、宿主机敏感路径或环境值。
- `ops_alert_events`：保存告警时间线，包含 action、actor、状态流转、备注和轻量 metadata。
- `ops_alert_settings`：保存通知策略，不保存 Telegram token、chat id、邮件收件人或其他密钥。
- `ops_alerts` 新增 `assigned_to`、`assigned_at`，用于当前负责人展示和筛选扩展。

## API

```text
GET  /api/ops/alerts/evaluations/latest
GET  /api/ops/alerts/settings
PUT  /api/ops/alerts/settings
POST /api/ops/alerts/{alert}/assign
```

`PUT /api/ops/alerts/settings` 请求体：

```json
{
  "notification_repeat_minutes": 30,
  "auto_resolve_enabled": true,
  "auto_resolve_grace_minutes": 5,
  "telegram_enabled": true,
  "mail_enabled": true,
  "severity_channels": {
    "critical": { "telegram": true, "mail": true },
    "warning": { "telegram": true, "mail": true },
    "info": { "telegram": true, "mail": true }
  }
}
```

## 内置规则

| Key | Source | Metric | Unit |
| --- | --- | --- | --- |
| `system_cpu` | `system` | `cpu_percent` | `%` |
| `system_memory` | `system` | `memory_percent` | `%` |
| `redis_connected` | `redis` | `connected` | `boolean` |
| `mysql_connected` | `mysql` | `connected` | `boolean` |
| `octane_running` | `octane` | `running` | `boolean` |
| `supervisor_process_down` | `supervisor` | `process_state` | `processes` |

这些规则仍由 `AlertRuleRegistryService` 白名单同步，页面不能创建任意表达式。采集字段缺失时不会误报；只有字段存在且明确异常时触发。

## 审计

- `ops.alerts / settings_update`
- `ops.alerts / assign`
- 既有 `evaluate`、`acknowledge`、`resolve`、`rule_update`、`rule_toggle` 保持不变。
- 审计 payload 只保存策略、负责人和状态摘要，不保存密钥、通知收件人或告警正文大 payload。

## 验收命令

```bash
git diff --check
npm run build
./vendor/bin/sail artisan route:list --path=ops
./vendor/bin/sail artisan test --filter PhaseEightAlertOperationsTest
./vendor/bin/sail artisan test --filter PhaseFourAlertCenterTest
./vendor/bin/sail artisan test --filter PhaseFiveSecurityTest
./vendor/bin/sail artisan test --filter AlertRuleEngineServiceTest
./vendor/bin/sail artisan test
```

静态检查：

```bash
rg -n "v-html|dangerouslyUseHTMLString|innerHTML|insertAdjacentHTML" resources/js/pages/ops/AlertCenter.vue resources/js/api/opsStage4.ts
rg -n "console\\.error\\(error\\)" resources/js
```

## 发布与回滚

- 发布前执行 migration，新增表和 `ops_alerts` 字段不会因代码回滚自动删除。
- 发布后执行一次 `./vendor/bin/sail artisan ops:alerts:evaluate`，再进入 `/admin/ops/alerts` 核对巡检状态和规则列表。
- 回滚代码后，通知策略表中的数据不会生效；旧代码继续按 `config('ops.alerts.*')` 读取。
- 历史告警和时间线不自动清理，避免丢失值班处理记录。
