# Ops Center Phase 18 — 异常登录通知推送

> 新 IP / 新设备登录 → 告警中心 + 现有通道推送

## 范围

phase 17 已把每次成功登录落库到 `admin_login_events` 并标记 `is_new_ip` / `is_new_user_agent`，但只写库、不通知。phase 14 已建好配置驱动的多通道通知。本阶段把两者接起来：管理员从**新 IP 或新设备**登录时，自动升起一条 `security_login` 告警（进告警中心），并经**已启用的通道**（telegram/mail/webhook/钉钉/飞书）推送，形成「登录风控 → 主动通知」闭环。

## 触发条件

在 `AdminAuthController::completeLogin`（2FA 挑战 / 绑定确认 / 受信任设备三条登录路径都会走到）里，`AdminLoginEventService::record()` 落库后调用 `AlertCenterService::raiseLoginAnomalyAlert($admin, $event)`。升起条件（全部满足）：

1. `config('ops.alerts.login_alerts.enabled')` 为真（默认开）。
2. `is_new_ip` 或 `is_new_user_agent` 为真。
3. **该管理员存在更早的登录事件**（首登抑制——绑定 2FA 后的首次登录没有历史，不算异常，避免刷屏）。

## 去重 / 冷却

复用 `AlertCenterService::storeAlert()`：`AlertDTO` 指纹取 `source|severity|title|context.target`，其中 `context.target = "admin:{id}:ip:{ip}"`，实现**按 管理员 + IP 去重**；重复命中只增 `hit_count`，仅首次或超过 `notification_repeat_minutes` 冷却才再次推送。登录告警**不在** `autoResolveRecoveredAlerts` 托管源列表中 → 不会被自动 resolve，留人工确认。

## 告警形状

- `source = security_login`，`severity = config`（默认 `warning`），`title = 异地/新设备登录`。
- `message`：`管理员 <email> 从 <新 IP / 新设备> 登录（IP：…，UA：…）`，经 `safeInspectionText` 二次脱敏 + 截断。
- `context`：`{target, admin_id, login_event_id, ip, is_new_ip, is_new_user_agent, trusted}`。
- 告警事件流水记 `new_ip_login` / `new_ip_login_refired`（actor `ops-security`）。

## 新增配置

`config/ops.php` 的 `alerts.login_alerts`：

| 键 | env | 默认 | 说明 |
|---|---|---|---|
| `enabled` | `OPS_LOGIN_ALERTS_ENABLED` | `true` | 总开关 |
| `severity` | `OPS_LOGIN_ALERTS_SEVERITY` | `warning` | 告警严重级（决定 severity×channel 矩阵路由） |

通道路由沿用 phase-14 的 `severity_channels` 矩阵，无新增开关。

## 安全边界 / 不做

- 只对**已启用通道**推送；凭据脱敏沿用 `AlertNotificationService::safeExceptionMessage`。
- 首登抑制 + 按 admin+IP 去重 + 冷却，避免刷屏。
- 登录告警不进托管自动恢复源，人工确认。
- 前端无需新增：告警自动出现在现有告警中心（`AlertCenter.vue`），账号安全页登录历史已有「新 IP/新设备」徽标（phase 17）。
- **不做**：GeoIP 地理解析、短信/邮件二次验证码、每设备独立通知开关。

## 验收

```bash
docker … php vendor/bin/phpunit --filter 'PhaseEighteenLoginAlert|AlertCenter'
docker … php vendor/bin/phpunit          # 全量回归
docker … php vendor/bin/pint <改动文件>
npm run build

# 手动
#   已绑定 2FA 的号从 A 网络登录（基线）→ 换 IP 登录 → 告警中心出现「异地/新设备登录」+ 已配置通道收到推送
#   同 IP 再登不重复；OPS_LOGIN_ALERTS_ENABLED=false 时不告警
```
