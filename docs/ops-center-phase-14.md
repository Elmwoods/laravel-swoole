# Ops Center 第十四阶段：告警通知通道扩展（Webhook + 钉钉 + 飞书，配置驱动）

## 范围

- 在 Telegram + 邮件基础上新增三个通知通道：**通用 Webhook**、**钉钉群机器人**、**飞书群机器人**。
- 通道接入改为**单一来源的配置驱动**：`config('ops.alerts.channels')` 列出所有通道，`AlertNotificationService` 的 `send/status/sendTest/channelAllowed`、`OpsAlertSetting` 默认值与"严重级 × 通道"矩阵、两个请求校验、前端 UI 均据此遍历，不再逐通道硬编码。
- 每通道支持总开关、按严重级路由、单独测试。

## 通道行为

- **Webhook**：POST JSON `{title, severity, source, status, message, time, fingerprint}`；配置 secret 时附带 `X-Ops-Signature: hmac-sha256(body, secret)` 请求头。
- **钉钉**：`{msgtype:'text', text:{content}}`；配置 secret 时按钉钉加签规范把 `timestamp` + `sign`（HMAC-SHA256，base64，URL 编码）拼到 webhook URL。
- **飞书**：`{msg_type:'text', content:{text}}`；配置 secret 时按飞书加签规范把 `timestamp` + `sign` 放入请求体。

## 新增环境变量（默认全部关闭）

```dotenv
OPS_ALERT_WEBHOOK_ENABLED=false
OPS_ALERT_WEBHOOK_URL=
OPS_ALERT_WEBHOOK_SECRET=

OPS_ALERT_DINGTALK_ENABLED=false
OPS_ALERT_DINGTALK_WEBHOOK=
OPS_ALERT_DINGTALK_SECRET=

OPS_ALERT_FEISHU_ENABLED=false
OPS_ALERT_FEISHU_WEBHOOK=
OPS_ALERT_FEISHU_SECRET=
```

## 接口

沿用既有告警接口（无新增路由）：

```text
GET /api/ops/alerts/notification-status   # 各通道 enabled/configured/missing
GET /api/ops/alerts/settings              # 含各通道总开关 + 严重级矩阵
PUT /api/ops/alerts/settings              # 权限 ops.alerts.manage
POST /api/ops/alerts/test-notification    # channels 白名单来自 config channels
```

## 安全边界

- `notification-status` 只返回 enabled/configured/missing，不返回 token、webhook URL、secret 等凭据。
- 发送失败日志经 `safeExceptionMessage` 脱敏，新增对 `access_token` / `sign` / secret 参数的过滤。
- 通道白名单单一来源（`config('ops.alerts.channels')`），请求校验与 Service `sendTest` 同源，避免前端传入任意通道字符串。
- 无需迁移：`ops_alert_settings` 表结构不变；旧 `severity_channels` 缺新通道 key 时按 `data_get(..., true)` 默认放行，向后兼容。
- 前端文本渲染，无 HTML 注入。

## 验收命令

```bash
git diff --check
npm run build
./vendor/bin/sail artisan test --filter PhaseFourteenNotificationChannelsTest
./vendor/bin/sail artisan test --filter PhaseFourAlertCenterTest
./vendor/bin/sail artisan test --filter PhaseEightAlertOperationsTest
./vendor/bin/sail artisan test
```

静态扫描：

```bash
rg -n "v-html|dangerouslyUseHTMLString|innerHTML|insertAdjacentHTML" resources/js/pages/ops/AlertCenter.vue
```
