# Ops Center Phase 29 — 告警值班静默窗口（维护窗口 / snooze）

## 范围

发布/维护时磁盘、队列、巡检等会短暂抖动、刷一堆告警外发，值班被噪声淹没。本阶段加**告警静默窗口**：
管理员配一个时间段 + 可选来源/严重级过滤，命中的告警**只入库、广播、可视化，但不外发到任何通道**——
维护窗口内静音，窗口结束自动恢复。标准 maintenance-window 语义，只压外发、不丢告警。

## 单一 choke point

所有 raiser（evaluate 循环 + `raiseInspectionAlert`/`raiseLoginAnomalyAlert`/`raiseAuditAnomalyAlert`/
`raiseAutoBanAlert`/`raiseChannelHealthAlert` + digest）都经 `AlertNotificationService::send(OpsAlert)` 外发。
在 `send()` 顶部判静默：命中 → 每通道返回 `reason='silenced'`、**不 dispatch**。

- **不影响** `sendTest()`（测试通知）与 `probeChannel()`（phase-26 通道健康探测）——它们不走 `send()`。
- raiser 的 `storeAlert → send(被静默 no-op) → recordAlertEvent → broadcast` 顺序不变 → 告警**照常入库/广播/在告警中心可见**，SLA（phase 28）与生命周期不受影响。

## 匹配语义

某静默命中当且仅当：`is_active` 且 `starts_at <= now <= ends_at` 且（`sources` 空=全部 或 含 `alert.source`）
且（`severities` 空=全部 或 含 `alert.severity`）。窗口结束（`ends_at < now`）自动失效，无需清理命令。
**boot-safe**：表缺失 / 查询出错 → 视为「未静默」，绝不阻断告警。

## 数据表 / 接口

- `ops_alert_silences`：`label`、`starts_at`、`ends_at`、`sources`(json 空=全部)、`severities`(json 空=全部)、`is_active`、`created_by`、时间戳。
- `GET /api/ops/alerts/silences`（`ops.alerts.view`）→ `{items, active}`。
- `POST` / `PATCH {id}` / `DELETE {id}`（`ops.alerts.manage` + 审计 `ops.alerts,silence_*`）。`ends_at` 必须晚于 `starts_at`（422）。

## 前端

- 「告警静默」页（`resources/js/pages/ops/AlertSilence.vue`，侧边栏「告警静默」）：active 静默 `el-alert` + 表（备注/时间段/来源/严重级/生效开关/删除）+ 新增 dialog（`datetimerange` + 来源/严重级多选，来源可自定义）。增删改按钮按 `ops.alerts.manage` 显隐。
- 告警中心 `AlertCenter.vue` 顶部：有 active 静默时显示 `el-alert`，让「为什么没收到推送」可见。

## 安全边界 / 不做

- **只压外发**：告警照常入库/广播/可见；`send()` 唯一 choke point，覆盖所有 raiser + digest；不影响测试/探测。
- 权限 `ops.alerts.manage`（管理）+ `ops.alerts.view`（查看）；source/severity 白名单化存储。
- **不做**：周期性静默（每晚 X 点，只做一次性时间段）、按规则/指纹精确静默单条、静默期结束「攒着补发」（本阶段直接丢弃外发，入库可回看）、逐条被静默告警的审计事件（用 active banner 提示）。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseTwentyNineAlertSilence'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
rg -n "v-html|innerHTML" resources/js/pages/ops/AlertSilence.vue
# 手动：建覆盖 now 的静默(source=disk) → 触发 disk 告警：告警中心可见但通道无推送、顶部显示静默生效；测试通知仍能发；窗口结束后恢复外发。
```
