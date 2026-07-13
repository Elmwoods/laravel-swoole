# Ops Center 金币助手交付说明

## 实现范围

- 每日金币领取状态摘要。
- 当日提醒状态标记。
- 人工确认领取、失败或跳过。
- 拒绝私有 token、抓包复用或绕过风控类自动化请求。
- 后台页面入口：`/admin/ops/daily-coin`。

## 文件路径

- Controller：`/Users/ggbond/PHPProjects/swoole/app/Http/Controllers/Admin/Ops/DailyCoinAssistantController.php`
- Service：`/Users/ggbond/PHPProjects/swoole/app/Services/Ops/DailyCoinAssistantService.php`
- Request：`/Users/ggbond/PHPProjects/swoole/app/Http/Requests/Admin/Ops/DailyCoinConfirmRequest.php`
- Request：`/Users/ggbond/PHPProjects/swoole/app/Http/Requests/Admin/Ops/DailyCoinAutomationRequest.php`
- API 路由：`/Users/ggbond/PHPProjects/swoole/routes/api.php`
- 前端 API：`/Users/ggbond/PHPProjects/swoole/resources/js/api/dailyCoinAssistant.ts`
- 前端页面：`/Users/ggbond/PHPProjects/swoole/resources/js/pages/ops/DailyCoinAssistant.vue`
- 前端路由：`/Users/ggbond/PHPProjects/swoole/resources/js/router/index.js`
- 侧边栏：`/Users/ggbond/PHPProjects/swoole/resources/js/layouts/AdminLayout.vue`
- 首页快捷入口：`/Users/ggbond/PHPProjects/swoole/resources/js/pages/Dashboard.vue`
- 测试：`/Users/ggbond/PHPProjects/swoole/tests/Feature/Ops/DailyCoinAssistantTest.php`

## API

- `GET /api/ops/coin-assistant/summary`
- `POST /api/ops/coin-assistant/reminder`
- `POST /api/ops/coin-assistant/confirm`
- `POST /api/ops/coin-assistant/automation-request`

## 安全约束

- 接口继承第五阶段后台登录与 `ops.system.view` 权限。
- 写操作进入第五阶段审计日志。
- 不接收或保存私有 token 自动化方案。
- 数据暂存于 Cache，避免引入外部平台账号或凭证。

## 验证命令

```bash
npm run build
./vendor/bin/sail artisan route:list --path=ops
./vendor/bin/sail artisan test --filter DailyCoinAssistantTest
```
