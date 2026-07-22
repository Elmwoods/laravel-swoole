# Ops Center Phase 19 — 告警通知聚合摘要

> 定时把一段窗口内的告警汇总成一条消息，经现有通道推送

## 范围

现有告警逐条实时推送，密集时刷屏、也没有「整体态势」视图。本阶段加一个**定时聚合摘要**：按时间窗口聚合告警（按严重级 / 状态 / 来源计数）→ 拼成一条汇总消息 → 经 phase-14 通道推送。复用现有告警数据与通知通道，**不新增表、不动前端**。

## 聚合口径

`AlertCenterService::digestSummary(int $hours)` 按 `created_at >= now()-hours`（窗口归一化 1–168）聚合：
- `total`
- `by_severity`：`critical / warning / info`
- `by_status`：`open / acknowledged / resolved`
- `sources`：Top 5 来源计数（复用 `summary()` 的 `count(*)+groupBy` 写法）

`renderDigest($summary)` 渲染纯文本多行正文（经 `safeInspectionText` 截断脱敏），示例：

```
过去 24 小时告警摘要（2026-07-22 08:00:00）
共 12 条（严重 2 / 警告 7 / 提示 3）
状态：待处理 5 / 已确认 2 / 已恢复 5
Top 来源：disk 4 / security_login 3 / queue 2
```

## 推送

`AlertCenterService::sendDigest(int $hours)`：config 未开 → 不发；窗口内无告警且未开 `send_when_empty` → 不发；否则构造**不落库**的合成 `OpsAlert`（`source=digest`、`severity=config`、`title=Ops Center 告警摘要`、`message=renderDigest`）→ `AlertNotificationService::send()`，经**已启用通道** + severity 矩阵路由（同 `sendTest` 的合成告警模式）。

## 命令 / 调度

```bash
ops:alerts:digest {--hours=} {--dry-run}
```
- `--hours` 覆盖 config 窗口（非法 → 退出码 1）。
- `--dry-run` 渲染并打印正文、**不发送**。
- 否则调 `sendDigest`；瞬时异常 → `warn` + 退出码 0（容错，防「命令失败→日志 ERROR→再被采集成告警」自澎环）。
- 调度：`routes/console.php` 每日 `dailyAt('08:00')`（config 关闭时命令内 no-op）。

## 新增配置

`config/ops.php` 的 `alerts.digest`：

| 键 | env | 默认 | 说明 |
|---|---|---|---|
| `enabled` | `OPS_ALERT_DIGEST_ENABLED` | `false` | 总开关（opt-in，避免意外外发） |
| `window_hours` | `OPS_ALERT_DIGEST_WINDOW_HOURS` | `24` | 聚合窗口（1–168） |
| `severity` | `OPS_ALERT_DIGEST_SEVERITY` | `info` | 合成告警严重级，决定矩阵路由 |
| `send_when_empty` | `OPS_ALERT_DIGEST_SEND_WHEN_EMPTY` | `false` | 窗口内无告警是否仍发 |

## 安全边界 / 不做

- digest 合成告警**不落库**；只经已启用通道推送、severity 经矩阵路由；正文脱敏 + 截断。
- 默认 **opt-in（enabled=false）**。
- **不做**：前端设置页（config/env 驱动）、UI 内 `OpsAlertSetting` 开关、按 GeoIP/地区聚合、每通道独立窗口。

## 验收

```bash
docker … php vendor/bin/phpunit --filter 'AlertDigest|PhaseNineteenAlertDigest|AlertCenter'
docker … php vendor/bin/phpunit          # 全量回归
docker … php vendor/bin/pint <改动文件>
npm run build

# 手动
OPS_ALERT_DIGEST_ENABLED=true 且配好一个通道：
sail artisan ops:alerts:digest --dry-run   # 看正文
sail artisan ops:alerts:digest             # 收到单条汇总；空窗口不发
sail artisan schedule:list | grep digest
```
