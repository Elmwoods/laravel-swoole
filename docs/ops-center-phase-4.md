# Ops Center 第四阶段交付说明

## 实现范围

- WebSocket 告警轻量推送
- Dashboard 实时能力延续：复用已有 Reverb / Echo，并新增 `ops.alerts` 频道
- 告警中心页面
- Telegram 告警配置位
- 邮件告警配置位
- Telegram / 邮件通知测试入口
- 告警规则评估命令与定时调度

## 文件路径

- 告警表迁移：`/Users/ggbond/PHPProjects/swoole/database/migrations/2026_07_06_040000_create_ops_alerts_table.php`
- 告警模型：`/Users/ggbond/PHPProjects/swoole/app/Models/OpsAlert.php`
- 告警 DTO：`/Users/ggbond/PHPProjects/swoole/app/DTO/Ops/AlertDTO.php`
- 告警事件：`/Users/ggbond/PHPProjects/swoole/app/Events/Ops/AlertTriggered.php`
- 告警规则引擎：`/Users/ggbond/PHPProjects/swoole/app/Services/Ops/AlertRuleEngineService.php`
- 告警中心服务：`/Users/ggbond/PHPProjects/swoole/app/Services/Ops/AlertCenterService.php`
- 通知服务：`/Users/ggbond/PHPProjects/swoole/app/Services/Ops/AlertNotificationService.php`
- 请求验证：`/Users/ggbond/PHPProjects/swoole/app/Http/Requests/Admin/Ops/AlertIndexRequest.php`
- 请求验证：`/Users/ggbond/PHPProjects/swoole/app/Http/Requests/Admin/Ops/AlertAcknowledgeRequest.php`
- 控制器：`/Users/ggbond/PHPProjects/swoole/app/Http/Controllers/Admin/Ops/AlertController.php`
- Artisan 命令：`/Users/ggbond/PHPProjects/swoole/app/Console/Commands/Ops/EvaluateAlertsCommand.php`
- 路由：`/Users/ggbond/PHPProjects/swoole/routes/api.php`
- 调度：`/Users/ggbond/PHPProjects/swoole/routes/console.php`
- 配置：`/Users/ggbond/PHPProjects/swoole/config/ops.php`
- 环境变量示例：`/Users/ggbond/PHPProjects/swoole/.env.example`
- Vue API：`/Users/ggbond/PHPProjects/swoole/resources/js/api/opsStage4.ts`
- Vue 页面：`/Users/ggbond/PHPProjects/swoole/resources/js/pages/ops/AlertCenter.vue`
- 前端路由：`/Users/ggbond/PHPProjects/swoole/resources/js/router/index.js`
- 侧边栏：`/Users/ggbond/PHPProjects/swoole/resources/js/layouts/AdminLayout.vue`
- 单元测试：`/Users/ggbond/PHPProjects/swoole/tests/Unit/Ops/AlertRuleEngineServiceTest.php`
- 接口测试：`/Users/ggbond/PHPProjects/swoole/tests/Feature/Ops/PhaseFourAlertCenterTest.php`

## API 文档

### 告警列表

- 方法：`GET`
- 地址：`/api/ops/alerts`
- 查询参数：
  - `status`：`open`、`acknowledged`、`resolved`
  - `severity`：`critical`、`warning`、`info`
  - `source`：告警来源，例如 `disk`、`queue`、`docker`、`network`
  - `page`：页码
  - `per_page`：每页数量，5-100

### 告警汇总

- 方法：`GET`
- 地址：`/api/ops/alerts/summary`
- 返回：
  - `open_total`
  - `critical`
  - `warning`
  - `info`
  - `sources`

### 手动评估告警

- 方法：`POST`
- 地址：`/api/ops/alerts/evaluate`
- 说明：采集 Disk、Queue、Docker、Network 摘要并生成告警。

### 测试告警通知

- 方法：`POST`
- 地址：`/api/ops/alerts/test-notification`
- 说明：发送一条测试通知，不写入告警表。
- Body：

```json
{
  "channels": ["telegram", "mail"],
  "message": "Ops Center 告警中心通知通道测试。"
}
```

### 确认告警

- 方法：`POST`
- 地址：`/api/ops/alerts/{id}/acknowledge`
- Body：

```json
{
  "acknowledged_by": "ops-user",
  "note": "已处理"
}
```

### 标记告警已恢复

- 方法：`POST`
- 地址：`/api/ops/alerts/{id}/resolve`
- 页面入口：告警中心表格“操作”列，`open` 和 `acknowledged` 状态都会显示“恢复”按钮。
- Body：

```json
{
  "acknowledged_by": "ops-user",
  "note": "已恢复"
}
```

## WebSocket 文档

- 频道：`ops.alerts`
- 事件：`.alert.triggered`
- Payload：只包含 `id`、`source`、`severity`、`title`、`message`、`status`、`hit_count`、`last_seen_at`
- 说明：禁止通过 WebSocket 推送大日志正文，详情通过 HTTP 分页读取。

## Docker + Sail + Octane 测试方法

```bash
sail artisan migrate
sail artisan optimize:clear
sail artisan route:list --path=ops/alerts
sail artisan test --filter AlertRuleEngineServiceTest
sail artisan test --filter PhaseFourAlertCenterTest
sail artisan ops:alerts:evaluate
curl -X POST http://127.0.0.1:8080/api/ops/alerts/test-notification \
  -H "Content-Type: application/json" \
  -d '{"channels":["telegram"],"message":"Ops Center Telegram 测试"}'
npm run build
```

## 环境变量

```env
OPS_ALERT_DISK_USAGE_WARNING=85
OPS_ALERT_DISK_USAGE_CRITICAL=95
OPS_ALERT_QUEUE_PENDING_WARNING=100
OPS_ALERT_FAILED_JOBS_WARNING=1
OPS_ALERT_NETWORK_MBPS_WARNING=50
OPS_ALERT_DOCKER_EXITED_ENABLED=true
OPS_ALERT_TELEGRAM_ENABLED=false
OPS_ALERT_TELEGRAM_BOT_TOKEN=
OPS_ALERT_TELEGRAM_CHAT_ID=
OPS_ALERT_MAIL_ENABLED=false
OPS_ALERT_MAIL_TO=
```
