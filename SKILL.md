# Ops Center Development Skill

## 适用场景

当需要继续开发、排错或审查本项目 Ops Center 运维后台时，使用本技能说明。

## 架构约定

后端：

- Laravel 13 路由入口：`routes/api.php`
- 控制器：`app/Http/Controllers/Admin/Ops`
- 请求验证：`app/Http/Requests/Admin/Ops`
- 服务层：`app/Services/Ops`
- 事件：`app/Events/Ops`

前端：

- Vue 入口：`resources/js/app.js`
- 路由：`resources/js/router/index.js`
- 统一布局：`resources/js/layouts/AdminLayout.vue`
- 页面：`resources/js/pages/ops`
- API 封装：`resources/js/api`

## 安全规则

- 容器 ID、Supervisor 进程名、PID 必须验证后再使用。
- 禁止使用 `dd()`、未转义 shell 拼接、`dangerouslyUseHTMLString` 展示外部数据。
- Docker logs、Laravel logs 等大文本禁止直接广播到 Pusher/Reverb。
- 控制类接口后续应接入 Sanctum 或后台 RBAC。

## 开发检查清单

- 新增 API 是否有 Request 验证。
- Service 是否可在 Docker/Sail/Octane 下运行。
- 前端是否使用 TypeScript 类型。
- 页面是否可在 Docker API 不可用时优雅降级。
- 是否执行 `npm run build`。
- PHP 测试需在 Sail 内执行。

## 强制交付流程

每次修改或新增需求都必须执行：

1. 代码 review：检查输入验证、命令执行、广播 payload、前端异常兜底。
2. 测试：优先执行 `npm run build`，有 Sail 环境时执行 Laravel 路由和测试命令。
3. 交付说明：列出已测项目、未测原因、残余风险和下一步建议。
