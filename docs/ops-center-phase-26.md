# Ops Center Phase 26 — 通知通道健康自检

## 范围

告警中心的 5 个通道（telegram/mail/webhook/钉钉/飞书）只在有告警时才发——某通道悄悄坏了
（token 失效、webhook URL 变更、SMTP 连不上）要等下一次真实告警才暴露「发不出去」。本阶段补一个
**定时连通性自检**：周期性静默探测每个已启用通道，记录每通道健康态，某通道**连续失败超阈值 → 升
`channel_health` 告警**（经其它仍可用的通道推送），恢复即自动 resolve；前端在通知通道卡片显示健康徽标。

## 探测方式（静默优先）

现有 `send`/`sendTest` 会真的投递消息，不能用于探测（会刷屏）。故新增 `probeChannel`，每通道用最轻的连通性检查：

| 通道 | 探测方式 | 是否投递消息 |
|---|---|---|
| telegram | `GET /getMe`（验证 token + 网络） | 否（静默） |
| mail | 打开 SMTP 传输连接（`transport->start()`） | 否（静默）；非 SMTP 驱动视为跳过 |
| webhook | POST 最小 `{event:'ops_health_check'}` 到配置 URL | 否（机器端点，非用户可见） |
| 钉钉 / 飞书 | 无静默 ping → 发一条明确标注的轻量心跳（errcode/code==0 视为连通） | **是**（可用 `probe_channels` 排除） |

只探测 **config 启用 + DB 开关 on + 凭据齐** 的通道，其余在 status() 里本就是「未就绪」，不探测（`checked_via='skipped'`，不计入健康统计）。探测绕过严重级投递策略（只测连通）；错误经 `safeExceptionMessage` 脱敏。

## 健康态 / 告警闭环

- 每通道健康存 `ops_channel_health`（channel unique、status、consecutive_failures、last_ok_at、last_checked_at、last_error）。
- 探测失败 → `consecutive_failures++`，达 `fail_threshold`（默认 2，去抖）→ `AlertCenterService::raiseChannelHealthAlert`（`source='channel_health'`、dedup `channel_health:{channel}`、severity warning）。
- 探测成功且该通道此前 failing → `resolveChannelHealthAlert`（显式关闭 open 告警，`channel_health` 不在托管自动恢复源）+ 计数归零。
- `notificationStatus()` 把每通道健康态合并进通知状态，前端徽标即时展示。

## 配置（env）

| 变量 | 默认 | 说明 |
|---|---|---|
| `OPS_ALERT_HEALTH_ENABLED` | false | 启用开关（opt-in；定时外拨 + 钉钉/飞书心跳） |
| `OPS_ALERT_HEALTH_FAIL_THRESHOLD` | 2 | 连续失败几次才升警（去抖） |
| `OPS_ALERT_HEALTH_PROBE_CHANNELS` | 空 | 限定主动探测的通道（csv）；空=全部已启用。用于排除钉钉/飞书心跳 |

## 命令 / 调度 / 手动

- `ops:alerts:health-check {--dry-run}`——容错（异常→退出码 0）；调度 `everyThirtyMinutes()->withoutOverlapping()`（低频，减少心跳噪声）。
- 手动：`POST /api/ops/alerts/health-check`（`ops.alerts.manage` + 审计），前端「立即自检」按钮触发，返回更新后的通知状态。

## 安全边界 / 不做

- 默认 opt-in 关闭 + 低频 + telegram/mail/webhook 静默 + 钉钉/飞书可排除，最小化噪声。
- 探测只覆盖已配置通道；正文/错误脱敏，不落 token/URL/secret。
- 去抖阈值避免单次抖动误报；`channel_health` 恢复走显式 resolve。
- **不做**：端到端投递回执（telegram/mail 只验证连通、不验证对方真的收到）、每通道 SLA/延迟统计、健康历史趋势图（只存当前态）。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseTwentySixChannelHealth|ScheduledCommandResilience'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
# 手动：配好一个通道 + OPS_ALERT_HEALTH_ENABLED=true → 改坏 token → ops:alerts:health-check ×2 →
#       告警中心出现 channel_health 告警、通知卡该通道红「连通异常」；改回 → 再自检 → 自动 resolve + 绿。
```
